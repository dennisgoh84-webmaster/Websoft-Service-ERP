<?php

namespace Tests\Feature;

use App\Exceptions\ContractRuleViolation;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Contract;
use App\Models\JobOrder;
use App\Models\ServiceRecord;
use App\Models\User;
use App\Services\ContractService;
use App\Services\ServiceRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit-level coverage of App\Services\ServiceRecordService, mirroring
 * backend/app/services/service_records.py's SRV-003/004/007/015 logic
 * exactly -- flagged in docs/php-conversion-plan.md as needing a
 * dedicated test suite, not just a smoke test.
 */
class ServiceRecordServiceTest extends TestCase
{
    use RefreshDatabase;

    // ---- SRV-007: round up to nearest 15 minutes -------------------------

    public function test_rounding_examples_from_srv_007(): void
    {
        $this->assertSame(30, ServiceRecord::roundUpToNearest(23));
        $this->assertSame(15, ServiceRecord::roundUpToNearest(5));
        $this->assertSame(15, ServiceRecord::roundUpToNearest(15));
        $this->assertSame(0, ServiceRecord::roundUpToNearest(0));
        $this->assertSame(0, ServiceRecord::roundUpToNearest(-10));
        $this->assertSame(60, ServiceRecord::roundUpToNearest(46));
    }

    // ---- Suggested deduction multiplier (2026-09-11) ---------------------

    public function test_suggested_deduction_is_unmultiplied_by_default(): void
    {
        $this->assertSame(60, ServiceRecordService::suggestedDeductionMinutes(60, false, false));
    }

    public function test_suggested_deduction_urgent_multiplier(): void
    {
        $this->assertSame(90, ServiceRecordService::suggestedDeductionMinutes(60, true, false));
    }

    public function test_suggested_deduction_after_hours_multiplier(): void
    {
        $this->assertSame(120, ServiceRecordService::suggestedDeductionMinutes(60, false, true));
    }

    public function test_suggested_deduction_higher_multiplier_wins_not_both(): void
    {
        // Urgent (x1.5) AND after-hours (x2.0) -> 2.0 wins, not 3.0.
        $this->assertSame(120, ServiceRecordService::suggestedDeductionMinutes(60, true, true));
    }

    // ---- Submission -----------------------------------------------------

    public function test_cannot_submit_against_a_closed_job_order(): void
    {
        [$jobOrder, $employee] = $this->jobOrderAndEmployee(['status' => JobOrder::STATUS_CLOSED]);

        $this->expectException(ContractRuleViolation::class);
        ServiceRecordService::submitServiceRecord(
            jobOrderId: $jobOrder->id, employeeUserId: $employee->id, workDate: now()->toDateString(),
            rawMinutes: 30, actorUserId: $employee->id,
        );
    }

    public function test_submission_rounds_up_to_nearest_15_minutes(): void
    {
        [$jobOrder, $employee] = $this->jobOrderAndEmployee();

        $record = ServiceRecordService::submitServiceRecord(
            jobOrderId: $jobOrder->id, employeeUserId: $employee->id, workDate: now()->toDateString(),
            rawMinutes: 23, actorUserId: $employee->id,
        );

        $this->assertSame(23, $record->raw_minutes);
        $this->assertSame(30, $record->rounded_minutes);
        $this->assertSame(ServiceRecord::STATUS_SUBMITTED, $record->status);
    }

    // ---- Approval guards --------------------------------------------------

    public function test_only_approver_roles_can_approve(): void
    {
        [$jobOrder, $employee, , $contract] = $this->jobOrderAndEmployee([], activateContract: true);
        $record = $this->submitRecord($jobOrder, $employee, 60);
        $nonApprover = User::factory()->for(Company::find($jobOrder->company_id))->create(['role' => User::ROLE_SUPPORT_ENGINEER]);

        $this->expectException(ContractRuleViolation::class);
        ServiceRecordService::approveServiceRecord($record, $jobOrder, $nonApprover, 60);
    }

    public function test_cannot_approve_an_already_approved_record(): void
    {
        [$jobOrder, $employee, $approver] = $this->jobOrderAndEmployee([], activateContract: true);
        $record = $this->submitRecord($jobOrder, $employee, 60);
        ServiceRecordService::approveServiceRecord($record, $jobOrder, $approver, 60);

        $this->expectException(ContractRuleViolation::class);
        ServiceRecordService::approveServiceRecord($record->fresh(), $jobOrder, $approver, 60);
    }

    public function test_approving_requires_a_linked_contract(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $employee = User::factory()->for($company)->create();
        $approver = User::factory()->for($company)->create(['role' => User::ROLE_OWNER]);
        $jobOrder = JobOrder::factory()->for($company)->create(['customer_id' => $customer->id, 'contract_id' => null]);
        $record = $this->submitRecord($jobOrder, $employee, 60);

        $this->expectException(ContractRuleViolation::class);
        ServiceRecordService::approveServiceRecord($record, $jobOrder, $approver, 60);
    }

    // ---- SRV-003: contract deduction vs. excess usage split --------------

    public function test_approval_deducts_from_contract_when_hours_remain(): void
    {
        [$jobOrder, $employee, $approver, $contract] = $this->jobOrderAndEmployee([], activateContract: true, contractedMinutes: 600);
        $record = $this->submitRecord($jobOrder, $employee, 60);

        $excess = ServiceRecordService::approveServiceRecord($record, $jobOrder, $approver, 60);

        $this->assertNull($excess);
        $this->assertSame(ServiceRecord::OUTCOME_CONTRACT_DEDUCTION, $record->fresh()->outcome);
        $this->assertSame(60, $contract->fresh()->consumed_minutes);
    }

    public function test_approval_splits_into_excess_usage_when_balance_insufficient(): void
    {
        [$jobOrder, $employee, $approver, $contract] = $this->jobOrderAndEmployee([], activateContract: true, contractedMinutes: 600);
        // Use up all but 20 minutes first.
        ContractService::deductMinutes($contract, 580, $approver->id);
        $record = $this->submitRecord($jobOrder, $employee, 60);

        $excess = ServiceRecordService::approveServiceRecord($record->fresh(), $jobOrder, $approver, 60);

        $this->assertNotNull($excess);
        $this->assertSame(40, $excess->excess_minutes); // 60 requested - 20 remaining
        $this->assertSame(ServiceRecord::OUTCOME_EXCESS_USAGE, $record->fresh()->outcome);
        $this->assertSame(0, $contract->fresh()->remainingMinutes());
        $this->assertSame(Contract::STATUS_EXCEEDED, $contract->fresh()->status);
    }

    public function test_approval_never_creates_a_negative_contract_balance(): void
    {
        [$jobOrder, $employee, $approver, $contract] = $this->jobOrderAndEmployee([], activateContract: true, contractedMinutes: 600);
        ContractService::deductMinutes($contract, 600, $approver->id); // fully exhausted
        $record = $this->submitRecord($jobOrder, $employee, 30);

        ServiceRecordService::approveServiceRecord($record->fresh(), $jobOrder, $approver, 30);

        $this->assertSame(0, $contract->fresh()->remainingMinutes());
        $this->assertGreaterThanOrEqual(0, $contract->fresh()->consumed_minutes);
    }

    public function test_annual_contract_work_is_not_hour_metered(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $approver = User::factory()->for($company)->create(['role' => User::ROLE_OWNER]);
        $contract = ContractService::createContract(
            companyId: $company->id, customerId: $customer->id, contractedHours: 0,
            contractValueSgd: 5000, startDate: now()->toDateString(), actorUserId: $approver->id,
            contractKind: Contract::KIND_ANNUAL,
        );
        ContractService::activateContract($contract, $approver->id);
        $employee = User::factory()->for($company)->create();
        $jobOrder = JobOrder::factory()->for($company)->create(['customer_id' => $customer->id, 'contract_id' => $contract->id]);
        $record = $this->submitRecord($jobOrder, $employee, 60);

        $excess = ServiceRecordService::approveServiceRecord($record->fresh(), $jobOrder, $approver, 60);

        $this->assertNull($excess);
        $this->assertSame(ServiceRecord::OUTCOME_NOT_HOUR_METERED, $record->fresh()->outcome);
        $this->assertSame(0, $contract->fresh()->consumed_minutes);
    }

    // ---- Auto-close (confirmed 2026-09-11) --------------------------------

    public function test_job_order_auto_closes_when_latest_record_approved_and_completed(): void
    {
        [$jobOrder, $employee, $approver] = $this->jobOrderAndEmployee([], activateContract: true, contractedMinutes: 600);
        $record = $this->submitRecord($jobOrder, $employee, 60, ServiceRecord::COMPLETED);

        ServiceRecordService::approveServiceRecord($record->fresh(), $jobOrder, $approver, 60);

        $this->assertSame(JobOrder::STATUS_CLOSED, $jobOrder->fresh()->status);
        $this->assertNotNull($jobOrder->fresh()->closed_at);
    }

    public function test_job_order_stays_open_when_latest_record_is_uncompleted(): void
    {
        [$jobOrder, $employee, $approver] = $this->jobOrderAndEmployee([], activateContract: true, contractedMinutes: 600);
        $record = $this->submitRecord($jobOrder, $employee, 60, ServiceRecord::UNCOMPLETED);

        ServiceRecordService::approveServiceRecord($record->fresh(), $jobOrder, $approver, 60);

        $this->assertNotSame(JobOrder::STATUS_CLOSED, $jobOrder->fresh()->status);
    }

    public function test_earlier_uncompleted_record_does_not_block_close_once_final_visit_is_done(): void
    {
        [$jobOrder, $employee, $approver] = $this->jobOrderAndEmployee([], activateContract: true, contractedMinutes: 600);
        $first = $this->submitRecord($jobOrder, $employee, 30, ServiceRecord::UNCOMPLETED, now()->subDay()->toDateString());
        ServiceRecordService::approveServiceRecord($first->fresh(), $jobOrder, $approver, 30);
        $this->assertNotSame(JobOrder::STATUS_CLOSED, $jobOrder->fresh()->status);

        $second = $this->submitRecord($jobOrder, $employee, 30, ServiceRecord::COMPLETED, now()->toDateString());
        ServiceRecordService::approveServiceRecord($second->fresh(), $jobOrder, $approver, 30);

        $this->assertSame(JobOrder::STATUS_CLOSED, $jobOrder->fresh()->status);
    }

    /**
     * @return array{0: JobOrder, 1: User, 2: User, 3: ?Contract}
     */
    private function jobOrderAndEmployee(array $jobOrderOverrides = [], bool $activateContract = false, int $contractedMinutes = 600): array
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $employee = User::factory()->for($company)->create();
        $approver = User::factory()->for($company)->create(['role' => User::ROLE_OWNER]);

        $contract = Contract::factory()->for($company)->create([
            'customer_id' => $customer->id,
            'contracted_minutes' => $contractedMinutes,
            'status' => $activateContract ? Contract::STATUS_ACTIVE : Contract::STATUS_DRAFT,
        ]);

        $jobOrder = JobOrder::factory()->for($company)->create(array_merge([
            'customer_id' => $customer->id, 'contract_id' => $contract->id,
        ], $jobOrderOverrides));

        return [$jobOrder, $employee, $approver, $contract];
    }

    private function submitRecord(JobOrder $jobOrder, User $employee, int $rawMinutes, string $completion = ServiceRecord::UNCOMPLETED, ?string $workDate = null): ServiceRecord
    {
        return ServiceRecordService::submitServiceRecord(
            jobOrderId: $jobOrder->id, employeeUserId: $employee->id,
            workDate: $workDate ?? now()->toDateString(), rawMinutes: $rawMinutes,
            actorUserId: $employee->id, completionStatus: $completion,
        );
    }
}
