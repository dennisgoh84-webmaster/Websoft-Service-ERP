<?php

namespace Tests\Feature;

use App\Exceptions\ContractRuleViolation;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Contract;
use App\Models\ExcessUsageRecord;
use App\Models\Invoice;
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
        $approver = User::factory()->for($company)->create(['role' => User::ROLE_SERVICE_LEAD]);
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

    public function test_billable_decision_issues_an_invoice_at_the_blended_rate(): void
    {
        [$excess, $contract, $approver] = $this->excessRecord();

        $invoice = ExcessUsageService::decideExcessUsage($excess, $contract, ExcessUsageRecord::TREATMENT_BILLABLE, 'Customer requested extra work', $approver);

        $this->assertNotNull($invoice);
        $this->assertTrue($excess->fresh()->invoiced);
        $this->assertSame(Invoice::TYPE_EXCESS_USAGE, $invoice->invoice_type);
        // 40 excess minutes = 2/3 hr, contract is SGD 3000 / 10 hrs = SGD 300/hr blended rate.
        $this->assertEqualsWithDelta(200.0, $invoice->fresh()->amount_sgd, 0.01);
    }

    public function test_non_billable_decision_does_not_issue_an_invoice(): void
    {
        [$excess, $contract, $approver] = $this->excessRecord();

        $invoice = ExcessUsageService::decideExcessUsage($excess, $contract, ExcessUsageRecord::TREATMENT_WARRANTY_GOODWILL, 'Goodwill gesture', $approver);

        $this->assertNull($invoice);
        $this->assertFalse($excess->fresh()->invoiced);
    }
}
