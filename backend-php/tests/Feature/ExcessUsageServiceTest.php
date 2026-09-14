<?php

namespace Tests\Feature;

use App\Exceptions\ContractRuleViolation;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Contract;
use App\Models\ExcessUsageRecord;
use App\Models\JobOrder;
use App\Models\User;
use App\Services\ContractService;
use App\Services\ExcessUsageService;
use App\Services\ServiceRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit-level coverage of App\Services\ExcessUsageService, mirroring
 * backend/app/services/excess_usage.py's decide_excess_usage exactly
 * (SRV-004/008/011/013). See docs/php-conversion-plan.md's "after
 * converting each module" checklist.
 */
class ExcessUsageServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: ExcessUsageRecord, 1: Contract, 2: User} */
    private function excessRecord(): array
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $employee = User::factory()->for($company)->create();
        $approver = User::factory()->for($company)->create(['role' => User::ROLE_OWNER]);
        $contract = Contract::factory()->for($company)->create([
            'customer_id' => $customer->id, 'contracted_minutes' => 600, 'status' => Contract::STATUS_ACTIVE,
        ]);
        ContractService::deductMinutes($contract, 580, $approver->id); // leave 20 min remaining
        $jobOrder = JobOrder::factory()->for($company)->create(['customer_id' => $customer->id, 'contract_id' => $contract->id]);
        $record = ServiceRecordService::submitServiceRecord(
            jobOrderId: $jobOrder->id, employeeUserId: $employee->id, workDate: now()->toDateString(),
            rawMinutes: 60, actorUserId: $employee->id,
        );
        ServiceRecordService::approveServiceRecord($record->fresh(), $jobOrder, $approver, 60);

        $excess = ExcessUsageRecord::where('service_record_id', $record->id)->firstOrFail();

        return [$excess, $contract->fresh(), $approver];
    }

    public function test_only_reviewer_roles_can_decide(): void
    {
        [$excess, $contract] = $this->excessRecord();
        $nonReviewer = User::factory()->for(Company::find($excess->company_id))->create(['role' => User::ROLE_SUPPORT_ENGINEER]);

        $this->expectException(ContractRuleViolation::class);
        ExcessUsageService::decideExcessUsage($excess, $contract, ExcessUsageRecord::TREATMENT_WARRANTY_GOODWILL, 'Goodwill gesture', $nonReviewer);
    }

    public function test_a_reason_is_required(): void
    {
        [$excess, $contract, $approver] = $this->excessRecord();

        $this->expectException(ContractRuleViolation::class);
        ExcessUsageService::decideExcessUsage($excess, $contract, ExcessUsageRecord::TREATMENT_WARRANTY_GOODWILL, '   ', $approver);
    }

    public function test_cannot_decide_an_already_decided_record(): void
    {
        [$excess, $contract, $approver] = $this->excessRecord();
        ExcessUsageService::decideExcessUsage($excess, $contract, ExcessUsageRecord::TREATMENT_WARRANTY_GOODWILL, 'Goodwill gesture', $approver);

        $this->expectException(ContractRuleViolation::class);
        ExcessUsageService::decideExcessUsage($excess->fresh(), $contract, ExcessUsageRecord::TREATMENT_OTHER, 'Second decision', $approver);
    }

    public function test_deciding_records_treatment_reason_and_reviewer(): void
    {
        [$excess, $contract, $approver] = $this->excessRecord();

        ExcessUsageService::decideExcessUsage($excess, $contract, ExcessUsageRecord::TREATMENT_INTERNAL_WRITE_OFF, 'Our error, writing off', $approver);

        $fresh = $excess->fresh();
        $this->assertSame(ExcessUsageRecord::TREATMENT_INTERNAL_WRITE_OFF, $fresh->treatment);
        $this->assertSame('Our error, writing off', $fresh->reason);
        $this->assertSame($approver->id, $fresh->decided_by_user_id);
        $this->assertNotNull($fresh->decided_at);
        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'excess_usage_record', 'entity_id' => $excess->id, 'action' => 'decided',
        ]);
    }

    public function test_billable_decision_does_not_invoice_yet_known_gap(): void
    {
        [$excess, $contract, $approver] = $this->excessRecord();

        ExcessUsageService::decideExcessUsage($excess, $contract, ExcessUsageRecord::TREATMENT_BILLABLE, 'Customer requested extra work', $approver);

        // KNOWN GAP: Billing isn't converted yet, so `invoiced` stays
        // false even for a BILLABLE decision -- see
        // ExcessUsageService's class docblock.
        $this->assertFalse($excess->fresh()->invoiced);
    }
}
