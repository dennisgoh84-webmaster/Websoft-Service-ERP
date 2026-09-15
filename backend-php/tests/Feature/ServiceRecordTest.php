<?php

namespace Tests\Feature;

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
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API-level coverage of App\Http\Controllers\Api\ServiceRecordController,
 * mirroring backend/app/routers/service_records.py. See
 * docs/php-conversion-plan.md's "after converting each module"
 * checklist. Business-rule arithmetic is covered separately in
 * ServiceRecordServiceTest.php.
 */
class ServiceRecordTest extends TestCase
{
    use RefreshDatabase;

    private function ownerToken(Company $company): array
    {
        $owner = User::factory()->for($company)->create([
            'role' => User::ROLE_OWNER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $login = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234']);

        return [$owner, $login->json('access_token')];
    }

    /** SRV-019: Service Records are approved by Nico (Service Lead) or Cherish (Sales Manager), never the owner. */
    private function nicoToken(Company $company): array
    {
        ModuleCatalog::firstOrCreate(['key' => 'service_records'], ['name' => 'Service Records', 'is_built' => true]);
        CompanyModule::firstOrCreate(['company_id' => $company->id, 'module_key' => 'service_records'], ['enabled' => true]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'service_records', 'access_level' => GroupModuleAuthority::FULL]);
        $nico = User::factory()->for($company)->create([
            'role' => User::ROLE_SERVICE_LEAD,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        UserCompanyAccess::create(['user_id' => $nico->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $login = $this->post('/api/auth/login', ['username' => $nico->email, 'password' => 'demo1234']);

        return [$nico, $login->json('access_token')];
    }

    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    private function jobOrder(Company $company): array
    {
        $customer = CompanyIndividual::factory()->for($company)->create();
        $contract = Contract::factory()->for($company)->create([
            'customer_id' => $customer->id, 'status' => Contract::STATUS_ACTIVE,
        ]);
        $jobOrder = JobOrder::factory()->for($company)->create(['customer_id' => $customer->id, 'contract_id' => $contract->id]);

        return [$jobOrder, $contract];
    }

    public function test_owner_can_submit_and_list_service_records(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        [$jobOrder] = $this->jobOrder($company);

        $create = $this->postJson('/api/service-records', [
            'job_order_id' => $jobOrder->id,
            'employee_user_id' => $owner->id,
            'work_date' => now()->toDateString(),
            'raw_minutes' => 23,
        ], $this->headers($token));

        $create->assertOk()->assertJson(['raw_minutes' => 23, 'rounded_minutes' => 30, 'status' => 'submitted']);
        $this->assertStringStartsWith('SR-', $create->json('service_record_number'));

        $this->getJson('/api/service-records', $this->headers($token))->assertOk()->assertJsonCount(1);
    }

    public function test_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/service-records', $this->headers($login->json('access_token')))->assertStatus(403);
    }

    public function test_service_record_from_another_company_is_not_found(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        [, $token] = $this->ownerToken($companyA);
        $foreignRecord = ServiceRecord::factory()->for($companyB)->create();

        $this->getJson("/api/service-records/{$foreignRecord->id}", $this->headers($token))->assertStatus(404);
    }

    public function test_pending_approval_lists_submitted_records_with_suggested_deduction(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        [$jobOrder] = $this->jobOrder($company);
        $jobOrder->update(['is_urgent' => true]);

        $this->postJson('/api/service-records', [
            'job_order_id' => $jobOrder->id, 'employee_user_id' => $owner->id,
            'work_date' => now()->toDateString(), 'raw_minutes' => 60,
        ], $this->headers($token));

        $response = $this->getJson('/api/service-records/pending-approval', $this->headers($token));

        $response->assertOk()->assertJsonCount(1);
        $this->assertSame(90, $response->json('0.suggested_deducted_minutes')); // 60 rounded * 1.5 urgent
        $this->assertSame(600, $response->json('0.contract_remaining_minutes'));
    }

    public function test_approve_deducts_from_contract_and_returns_outcome(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        [$jobOrder, $contract] = $this->jobOrder($company);
        $create = $this->postJson('/api/service-records', [
            'job_order_id' => $jobOrder->id, 'employee_user_id' => $owner->id,
            'work_date' => now()->toDateString(), 'raw_minutes' => 60,
        ], $this->headers($token));

        [, $nicoToken] = $this->nicoToken($company);
        $response = $this->postJson(
            "/api/service-records/{$create->json('id')}/approve",
            ['deducted_minutes' => 60],
            $this->headers($nicoToken),
        );

        $response->assertOk()->assertJson(['status' => 'approved', 'outcome' => 'contract_deduction']);
        $this->assertSame(60, $contract->fresh()->consumed_minutes);
    }

    public function test_approve_rejects_zero_minutes(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        [$jobOrder] = $this->jobOrder($company);
        $create = $this->postJson('/api/service-records', [
            'job_order_id' => $jobOrder->id, 'employee_user_id' => $owner->id,
            'work_date' => now()->toDateString(), 'raw_minutes' => 60,
        ], $this->headers($token));

        [, $nicoToken] = $this->nicoToken($company);
        $this->postJson(
            "/api/service-records/{$create->json('id')}/approve",
            ['deducted_minutes' => 0],
            $this->headers($nicoToken),
        )->assertStatus(422);
    }

    public function test_non_approver_role_cannot_approve(): void
    {
        $company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'service_records'], ['name' => 'Service Records', 'is_built' => true]);
        CompanyModule::create(['company_id' => $company->id, 'module_key' => 'service_records', 'enabled' => true]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'service_records', 'access_level' => GroupModuleAuthority::FULL]);
        $staff = User::factory()->for($company)->create(['role' => User::ROLE_SUPPORT_ENGINEER, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        UserCompanyAccess::create(['user_id' => $staff->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $login = $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234']);
        $token = $login->json('access_token');

        [$jobOrder] = $this->jobOrder($company);
        $create = $this->postJson('/api/service-records', [
            'job_order_id' => $jobOrder->id, 'employee_user_id' => $staff->id,
            'work_date' => now()->toDateString(), 'raw_minutes' => 60,
        ], $this->headers($token));

        $this->postJson(
            "/api/service-records/{$create->json('id')}/approve",
            ['deducted_minutes' => 60],
            $this->headers($token),
        )->assertStatus(422);
    }
}
