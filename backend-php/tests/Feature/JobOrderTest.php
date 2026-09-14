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
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API-level coverage of App\Http\Controllers\Api\JobOrderController,
 * mirroring backend/app/routers/job_orders.py. See
 * docs/php-conversion-plan.md's "after converting each module"
 * checklist.
 */
class JobOrderTest extends TestCase
{
    use RefreshDatabase;

    private function ownerToken(Company $company): string
    {
        $owner = User::factory()->for($company)->create([
            'role' => User::ROLE_OWNER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $login = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234']);

        return $login->json('access_token');
    }

    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    private function customerAndContract(Company $company): array
    {
        $customer = CompanyIndividual::factory()->for($company)->create();
        $contract = Contract::factory()->for($company)->create(['customer_id' => $customer->id]);

        return [$customer, $contract];
    }

    public function test_owner_can_create_and_list_job_orders(): void
    {
        $company = Company::factory()->create();
        [$customer, $contract] = $this->customerAndContract($company);
        $token = $this->ownerToken($company);

        $create = $this->postJson('/api/job-orders', [
            'customer_id' => $customer->id,
            'contract_id' => $contract->id,
            'subject' => 'Printer not working',
            'priority' => 'high',
        ], $this->headers($token));

        $create->assertOk()->assertJson(['status' => 'open', 'priority' => 'high']);
        $this->assertStringStartsWith('JO-', $create->json('job_order_number'));

        $this->getJson('/api/job-orders', $this->headers($token))->assertOk()->assertJsonCount(1);
    }

    public function test_project_type_auto_creates_five_milestones(): void
    {
        $company = Company::factory()->create();
        [$customer, $contract] = $this->customerAndContract($company);
        $token = $this->ownerToken($company);

        $response = $this->postJson('/api/job-orders', [
            'customer_id' => $customer->id,
            'contract_id' => $contract->id,
            'subject' => 'Office relocation',
            'job_order_type' => 'project',
        ], $this->headers($token));

        $response->assertOk();
        $this->assertCount(5, $response->json('milestones'));
        $this->assertSame('installation', $response->json('milestones.0.milestone_type'));
        $this->assertSame('completion_signoff', $response->json('milestones.4.milestone_type'));
    }

    public function test_support_type_does_not_create_milestones(): void
    {
        $company = Company::factory()->create();
        [$customer, $contract] = $this->customerAndContract($company);
        $token = $this->ownerToken($company);

        $response = $this->postJson('/api/job-orders', [
            'customer_id' => $customer->id,
            'contract_id' => $contract->id,
            'subject' => 'Laptop battery replacement',
        ], $this->headers($token));

        $response->assertOk()->assertJsonCount(0, 'milestones');
    }

    public function test_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/job-orders', $this->headers($login->json('access_token')))->assertStatus(403);
    }

    public function test_job_order_from_another_company_is_not_found(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $token = $this->ownerToken($companyA);
        [, $contractB] = $this->customerAndContract($companyB);
        $foreignJobOrder = JobOrder::factory()->for($companyB)->create([
            'customer_id' => $contractB->customer_id,
            'contract_id' => $contractB->id,
        ]);

        $this->getJson("/api/job-orders/{$foreignJobOrder->id}", $this->headers($token))->assertStatus(404);
    }

    public function test_assign_moves_status_to_assigned(): void
    {
        $company = Company::factory()->create();
        [$customer, $contract] = $this->customerAndContract($company);
        $token = $this->ownerToken($company);
        $assignee = User::factory()->for($company)->create();
        $jobOrder = JobOrder::factory()->for($company)->create(['customer_id' => $customer->id, 'contract_id' => $contract->id]);

        $response = $this->postJson("/api/job-orders/{$jobOrder->id}/assign", [
            'assigned_to_user_id' => $assignee->id,
        ], $this->headers($token));

        $response->assertOk()->assertJson(['status' => 'assigned', 'assigned_to_user_id' => $assignee->id]);
    }

    public function test_void_requires_a_reason_and_writes_audit_trail(): void
    {
        $company = Company::factory()->create();
        [$customer, $contract] = $this->customerAndContract($company);
        $token = $this->ownerToken($company);
        $jobOrder = JobOrder::factory()->for($company)->create(['customer_id' => $customer->id, 'contract_id' => $contract->id]);

        $this->postJson("/api/job-orders/{$jobOrder->id}/void", [], $this->headers($token))->assertStatus(422);

        $response = $this->postJson("/api/job-orders/{$jobOrder->id}/void", ['reason' => 'Duplicate of JO-2026-0001'], $this->headers($token));
        $response->assertOk()->assertJson(['status' => 'void', 'void_reason' => 'Duplicate of JO-2026-0001']);

        $this->assertDatabaseHas('audit_log_entries', ['entity_type' => 'job_order', 'entity_id' => $jobOrder->id, 'action' => 'voided']);
    }

    public function test_voiding_an_already_void_job_order_is_rejected(): void
    {
        $company = Company::factory()->create();
        [$customer, $contract] = $this->customerAndContract($company);
        $token = $this->ownerToken($company);
        $jobOrder = JobOrder::factory()->for($company)->create([
            'customer_id' => $customer->id, 'contract_id' => $contract->id, 'status' => JobOrder::STATUS_VOID,
        ]);

        $this->postJson("/api/job-orders/{$jobOrder->id}/void", ['reason' => 'x'], $this->headers($token))
            ->assertStatus(409);
    }

    public function test_only_owner_can_reopen(): void
    {
        $company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'service_operations'], ['name' => 'Service Operations', 'is_built' => true]);
        CompanyModule::create(['company_id' => $company->id, 'module_key' => 'service_operations', 'enabled' => true]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'service_operations', 'access_level' => GroupModuleAuthority::FULL]);
        $staff = User::factory()->for($company)->create(['role' => User::ROLE_SUPPORT_ENGINEER, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        UserCompanyAccess::create(['user_id' => $staff->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $login = $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234']);

        [$customer, $contract] = $this->customerAndContract($company);
        $jobOrder = JobOrder::factory()->for($company)->create([
            'customer_id' => $customer->id, 'contract_id' => $contract->id, 'status' => JobOrder::STATUS_VOID, 'void_reason' => 'x',
        ]);

        $this->postJson("/api/job-orders/{$jobOrder->id}/reopen", [], $this->headers($login->json('access_token')))
            ->assertStatus(403);
    }

    public function test_budget_overrun_approval_requires_sales_manager_or_owner(): void
    {
        $company = Company::factory()->create();
        [$customer, $contract] = $this->customerAndContract($company);
        $token = $this->ownerToken($company);
        $jobOrder = JobOrder::factory()->for($company)->create([
            'customer_id' => $customer->id, 'contract_id' => $contract->id, 'job_order_type' => JobOrder::TYPE_PROJECT,
        ]);

        $response = $this->postJson("/api/job-orders/{$jobOrder->id}/approve-overrun", [], $this->headers($token));

        $response->assertOk()->assertJson(['budget_overrun_approved' => true]);
    }

    public function test_only_sales_manager_or_owner_can_complete_a_milestone(): void
    {
        $company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'service_operations'], ['name' => 'Service Operations', 'is_built' => true]);
        CompanyModule::create(['company_id' => $company->id, 'module_key' => 'service_operations', 'enabled' => true]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'service_operations', 'access_level' => GroupModuleAuthority::FULL]);
        $staff = User::factory()->for($company)->create(['role' => User::ROLE_SUPPORT_ENGINEER, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        UserCompanyAccess::create(['user_id' => $staff->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $login = $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234']);

        [$customer, $contract] = $this->customerAndContract($company);
        $jobOrder = JobOrder::factory()->for($company)->create([
            'customer_id' => $customer->id, 'contract_id' => $contract->id, 'job_order_type' => JobOrder::TYPE_PROJECT,
        ]);
        $milestone = $jobOrder->milestones()->create(['milestone_type' => 'installation', 'label' => 'Installation', 'sort_order' => 0]);

        $this->putJson(
            "/api/job-orders/{$jobOrder->id}/milestones/{$milestone->id}",
            ['status' => 'completed'],
            $this->headers($login->json('access_token')),
        )->assertStatus(403);
    }

    public function test_milestones_endpoint_rejected_for_support_type(): void
    {
        $company = Company::factory()->create();
        [$customer, $contract] = $this->customerAndContract($company);
        $token = $this->ownerToken($company);
        $jobOrder = JobOrder::factory()->for($company)->create(['customer_id' => $customer->id, 'contract_id' => $contract->id]);

        $this->postJson("/api/job-orders/{$jobOrder->id}/milestones", [
            'milestone_type' => 'installation', 'label' => 'Installation',
        ], $this->headers($token))->assertStatus(409);
    }
}
