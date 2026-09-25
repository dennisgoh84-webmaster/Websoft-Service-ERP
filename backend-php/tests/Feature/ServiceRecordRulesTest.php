<?php

namespace Tests\Feature;

use App\Exceptions\ContractRuleViolation;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Contract;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\JobOrder;
use App\Models\ModuleCatalog;
use App\Models\ServiceRecord;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\ContractService;
use App\Services\PasswordPolicy;
use App\Services\ServiceRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The two Service Record rules Dennis settled 2026-09-15 (open items
 * 9.1 and 9.2): SRV-019 -- only Nico (Service Lead) and Cherish (Sales
 * Manager) approve, within a week of submission; SRV-020 -- whether
 * the time is contract-covered, billable or non-billable comes from
 * the Job Order's billing classification, never from the person
 * logging the record.
 */
class ServiceRecordRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_only_nico_and_cherish_may_approve_not_the_owner(): void
    {
        [$company, $jobOrder, $employee] = $this->activeJobOrder();

        $owner = User::factory()->for($company)->create(['role' => User::ROLE_OWNER]);
        $record = $this->submit($jobOrder, $employee);
        try {
            ServiceRecordService::approveServiceRecord($record, $jobOrder, $owner, 60);
            $this->fail('The owner approved a Service Record');
        } catch (ContractRuleViolation $e) {
            $this->assertStringContainsString('Only Nico (service_lead) or Cherish (sales_manager)', $e->getMessage());
        }
        $this->assertSame(ServiceRecord::STATUS_SUBMITTED, $record->fresh()->status);

        $cherish = User::factory()->for($company)->create(['role' => User::ROLE_SALES_MANAGER]);
        ServiceRecordService::approveServiceRecord($record->fresh(), $jobOrder, $cherish, 60);
        $this->assertSame(ServiceRecord::STATUS_APPROVED, $record->fresh()->status);

        $nico = User::factory()->for($company)->create(['role' => User::ROLE_SERVICE_LEAD]);
        $second = $this->submit($jobOrder, $employee);
        ServiceRecordService::approveServiceRecord($second, $jobOrder, $nico, 60);
        $this->assertSame(ServiceRecord::STATUS_APPROVED, $second->fresh()->status);
    }

    public function test_a_record_not_approved_within_a_week_is_flagged_overdue(): void
    {
        [$company, $jobOrder, $employee] = $this->activeJobOrder();

        Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00', 'UTC'));
        $record = $this->submit($jobOrder, $employee)->fresh();
        $this->assertSame(Carbon::parse('2026-09-08 09:00:00', 'UTC')->getTimestamp(), $record->approvalDueAt()->getTimestamp());
        $this->assertFalse($record->isApprovalOverdue());

        Carbon::setTestNow(Carbon::parse('2026-09-08 08:00:00', 'UTC'));
        $this->assertFalse($record->fresh()->isApprovalOverdue(), 'still within the week');

        Carbon::setTestNow(Carbon::parse('2026-09-08 10:00:00', 'UTC'));
        $this->assertTrue($record->fresh()->isApprovalOverdue());

        // Surfaced on the approval queue and the Company Dashboard, never auto-approved.
        [$nico, $h] = $this->login($company, User::ROLE_SERVICE_LEAD);
        $this->getJson('/api/service-records/pending-approval', $h)
            ->assertOk()->assertJsonPath('0.is_approval_overdue', true)->assertJsonPath('0.billing_classification', 'contract');
        $this->getJson('/api/dashboard/summary', $h)
            ->assertOk()->assertJsonPath('service_records_awaiting_approval', 1)->assertJsonPath('service_record_approvals_overdue', 1);
        $this->assertSame(ServiceRecord::STATUS_SUBMITTED, $record->fresh()->status);

        // Once approved it drops out of both counts.
        ServiceRecordService::approveServiceRecord($record->fresh(), $jobOrder, $nico, 60);
        $this->assertFalse($record->fresh()->isApprovalOverdue());
        $this->getJson('/api/dashboard/summary', $h)
            ->assertOk()->assertJsonPath('service_records_awaiting_approval', 0)->assertJsonPath('service_record_approvals_overdue', 0);
    }

    public function test_billable_job_order_time_is_never_deducted_from_the_contract(): void
    {
        [$company, $jobOrder, $employee, $contract] = $this->activeJobOrder(['billing_classification' => JobOrder::BILLING_BILLABLE]);
        $nico = User::factory()->for($company)->create(['role' => User::ROLE_SERVICE_LEAD]);

        $record = $this->submit($jobOrder, $employee);
        $excess = ServiceRecordService::approveServiceRecord($record, $jobOrder, $nico, 60);

        $this->assertNull($excess);
        $this->assertSame(ServiceRecord::OUTCOME_BILLABLE, $record->fresh()->outcome);
        $this->assertSame(60, $record->fresh()->deducted_minutes, 'the approver\'s figure is still recorded');
        $this->assertSame(0, $contract->fresh()->consumed_minutes, 'nothing left the contract hour pool');
    }

    public function test_non_billable_job_order_time_is_neither_deducted_nor_billed(): void
    {
        // Contract nearly exhausted (SRV-002's 10-hour minimum, 580 already
        // used): a CONTRACT-classified record of 60 minutes would have
        // raised excess usage, a NON_BILLABLE one must not.
        [$company, $jobOrder, $employee, $contract] = $this->activeJobOrder(['billing_classification' => JobOrder::BILLING_NON_BILLABLE]);
        $nico = User::factory()->for($company)->create(['role' => User::ROLE_SERVICE_LEAD]);
        ContractService::deductMinutes($contract, 580, $nico->id);

        $record = $this->submit($jobOrder, $employee);
        $excess = ServiceRecordService::approveServiceRecord($record, $jobOrder, $nico, 60);

        $this->assertNull($excess);
        $this->assertSame(ServiceRecord::OUTCOME_NON_BILLABLE, $record->fresh()->outcome);
        $this->assertSame(580, $contract->fresh()->consumed_minutes, 'unchanged');
        $this->assertDatabaseCount('excess_usage_records', 0);
    }

    public function test_contract_classification_is_the_default_and_keeps_the_original_behaviour(): void
    {
        [$company, $jobOrder, $employee, $contract] = $this->activeJobOrder();
        $this->assertSame(JobOrder::BILLING_CONTRACT, $jobOrder->fresh()->billing_classification);

        $nico = User::factory()->for($company)->create(['role' => User::ROLE_SERVICE_LEAD]);
        ServiceRecordService::approveServiceRecord($this->submit($jobOrder, $employee), $jobOrder, $nico, 60);
        $this->assertSame(60, $contract->fresh()->consumed_minutes);
    }

    public function test_classification_is_set_on_the_job_order_and_can_be_corrected_while_open(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $contract = Contract::factory()->for($company)->create(['customer_id' => $customer->id, 'status' => Contract::STATUS_ACTIVE]);
        [, $h] = $this->login($company, User::ROLE_OWNER);

        $created = $this->postJson('/api/job-orders', [
            'customer_id' => $customer->id, 'contract_id' => $contract->id, 'subject' => 'Site visit',
            'billing_classification' => 'billable',
        ], $h)->assertOk()->assertJsonPath('billing_classification', 'billable');

        $this->postJson('/api/job-orders', [
            'customer_id' => $customer->id, 'contract_id' => $contract->id, 'subject' => 'Default',
        ], $h)->assertOk()->assertJsonPath('billing_classification', 'contract');

        $this->postJson('/api/job-orders', [
            'customer_id' => $customer->id, 'contract_id' => $contract->id, 'subject' => 'Bad', 'billing_classification' => 'free',
        ], $h)->assertStatus(422);

        $id = $created->json('id');
        $this->postJson("/api/job-orders/{$id}/billing-classification", ['billing_classification' => 'non_billable'], $h)
            ->assertOk()->assertJsonPath('billing_classification', 'non_billable');
        $this->assertDatabaseHas('audit_log_entries', ['entity_type' => 'job_order', 'entity_id' => $id, 'action' => 'billing_classification_set']);

        $this->postJson("/api/job-orders/{$id}/void", ['reason' => 'raised in error'], $h)->assertOk();
        $this->postJson("/api/job-orders/{$id}/billing-classification", ['billing_classification' => 'contract'], $h)->assertStatus(409);
    }

    /** @return array{0: Company, 1: JobOrder, 2: User, 3: Contract} */
    private function activeJobOrder(array $jobOrderOverrides = [], int $contractedMinutes = 600): array
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $employee = User::factory()->for($company)->create();
        $contract = Contract::factory()->for($company)->create([
            'customer_id' => $customer->id, 'contracted_minutes' => $contractedMinutes, 'status' => Contract::STATUS_ACTIVE,
        ]);
        $jobOrder = JobOrder::factory()->for($company)->create(array_merge([
            'customer_id' => $customer->id, 'contract_id' => $contract->id,
        ], $jobOrderOverrides));

        return [$company, $jobOrder, $employee, $contract];
    }

    private function submit(JobOrder $jobOrder, User $employee): ServiceRecord
    {
        return ServiceRecordService::submitServiceRecord(
            jobOrderId: $jobOrder->id, employeeUserId: $employee->id,
            workDate: now()->toDateString(), rawMinutes: 60, actorUserId: $employee->id,
        );
    }

    /**
     * A signed-in user with FULL access to the Service Records and
     * Service Operations modules -- via a group for anyone but the owner.
     *
     * @return array{0: User, 1: array<string, string>}
     */
    private function login(Company $company, string $role): array
    {
        $group = Group::factory()->for($company)->create();
        foreach (['service_records', 'service_operations'] as $key) {
            ModuleCatalog::firstOrCreate(['key' => $key], ['name' => $key, 'is_built' => true]);
            CompanyModule::updateOrCreate(['company_id' => $company->id, 'module_key' => $key], ['enabled' => true]);
            GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => $key, 'access_level' => GroupModuleAuthority::FULL]);
        }
        $user = User::factory()->for($company)->create(['role' => $role, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        UserCompanyAccess::create(['user_id' => $user->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $token = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234'])->json('access_token');

        return [$user, ['Authorization' => "Bearer {$token}"]];
    }
}
