<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Contract;
use App\Models\ExcessUsageRecord;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\JobOrder;
use App\Models\ModuleCatalog;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\ContractService;
use App\Services\PasswordPolicy;
use App\Services\ServiceRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API-level coverage of App\Http\Controllers\Api\ExcessUsageController,
 * mirroring backend/app/routers/excess_usage.py. See
 * docs/php-conversion-plan.md's "after converting each module"
 * checklist. Business-rule arithmetic is covered separately in
 * ExcessUsageServiceTest.php.
 */
class ExcessUsageTest extends TestCase
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

    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    /** @return array{0: ExcessUsageRecord, 1: Company} */
    private function excessRecord(Company $company, User $approver): array
    {
        $customer = CompanyIndividual::factory()->for($company)->create();
        $employee = User::factory()->for($company)->create();
        $contract = Contract::factory()->for($company)->create([
            'customer_id' => $customer->id, 'contracted_minutes' => 600, 'status' => Contract::STATUS_ACTIVE,
        ]);
        ContractService::deductMinutes($contract, 580, $approver->id);
        $jobOrder = JobOrder::factory()->for($company)->create(['customer_id' => $customer->id, 'contract_id' => $contract->id]);
        $record = ServiceRecordService::submitServiceRecord(
            jobOrderId: $jobOrder->id, employeeUserId: $employee->id, workDate: now()->toDateString(),
            rawMinutes: 60, actorUserId: $employee->id,
        );
        ServiceRecordService::approveServiceRecord($record->fresh(), $jobOrder, $approver, 60);

        return [ExcessUsageRecord::where('service_record_id', $record->id)->firstOrFail(), $company];
    }

    public function test_owner_can_list_and_decide_excess_usage(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        [$excess] = $this->excessRecord($company, $owner);

        $list = $this->getJson('/api/excess-usage', $this->headers($token));
        $list->assertOk()->assertJsonCount(1);
        $this->assertSame(40 / 60, $list->json('0.excess_hours'));

        $decide = $this->postJson("/api/excess-usage/{$excess->id}/decide", [
            'treatment' => 'warranty_goodwill', 'reason' => 'Goodwill gesture for a valued customer',
        ], $this->headers($token));

        $decide->assertOk()->assertJson(['treatment' => 'warranty_goodwill']);
    }

    public function test_pending_only_filters_out_decided_records(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        [$excess] = $this->excessRecord($company, $owner);
        $this->postJson("/api/excess-usage/{$excess->id}/decide", [
            'treatment' => 'other', 'reason' => 'x',
        ], $this->headers($token));

        $response = $this->getJson('/api/excess-usage?pending_only=true', $this->headers($token));

        $response->assertOk()->assertJsonCount(0);
    }

    public function test_decide_requires_a_reason(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        [$excess] = $this->excessRecord($company, $owner);

        $this->postJson("/api/excess-usage/{$excess->id}/decide", [
            'treatment' => 'other', 'reason' => '',
        ], $this->headers($token))->assertStatus(422);
    }

    public function test_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/excess-usage', $this->headers($login->json('access_token')))->assertStatus(403);
    }

    public function test_excess_usage_from_another_company_is_not_found(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        [, $tokenA] = $this->ownerToken($companyA);
        [$ownerB] = $this->ownerToken($companyB);
        [$excessB] = $this->excessRecord($companyB, $ownerB);

        $this->postJson("/api/excess-usage/{$excessB->id}/decide", [
            'treatment' => 'other', 'reason' => 'x',
        ], $this->headers($tokenA))->assertStatus(404);
    }

    public function test_non_reviewer_role_cannot_decide(): void
    {
        $company = Company::factory()->create();
        [$owner] = $this->ownerToken($company);
        ModuleCatalog::firstOrCreate(['key' => 'service_contracts'], ['name' => 'Service Contracts', 'is_built' => true]);
        CompanyModule::create(['company_id' => $company->id, 'module_key' => 'service_contracts', 'enabled' => true]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'service_contracts', 'access_level' => GroupModuleAuthority::FULL]);
        $staff = User::factory()->for($company)->create(['role' => User::ROLE_SUPPORT_ENGINEER, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        UserCompanyAccess::create(['user_id' => $staff->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $login = $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234']);

        [$excess] = $this->excessRecord($company, $owner);

        $this->postJson("/api/excess-usage/{$excess->id}/decide", [
            'treatment' => 'other', 'reason' => 'x',
        ], $this->headers($login->json('access_token')))->assertStatus(422);
    }
}
