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
use App\Models\Product;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NEW FEATURE (not a Python->PHP conversion -- see
 * docs/backlog.md / docs/planned-work.md): "Product - To add in Job
 * Implementation Template" and "Job Order - To allow choosing of
 * multiple Products and Template to import according to Product".
 * Same template every module's test file follows -- see
 * docs/php-conversion-plan.md's "after converting each module"
 * checklist.
 */
class ProductImplementationTemplateTest extends TestCase
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

    // ---- Product Implementation Template CRUD ---------------------------

    public function test_owner_can_set_and_read_a_products_implementation_template(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $product = Product::factory()->for($company)->create();

        $response = $this->putJson("/api/catalog/{$product->id}/implementation-template", [
            'tasks' => [
                ['task_name' => 'Site survey'],
                ['task_name' => 'Install hardware', 'description' => 'Rack + cable'],
                ['task_name' => 'User training'],
            ],
        ], $this->headers($token));

        $response->assertOk();
        $this->assertCount(3, $response->json('tasks'));
        $this->assertSame('Site survey', $response->json('tasks.0.task_name'));
        $this->assertSame(0, $response->json('tasks.0.sort_order'));
        $this->assertSame('Install hardware', $response->json('tasks.1.task_name'));

        $read = $this->getJson("/api/catalog/{$product->id}/implementation-template", $this->headers($token));
        $read->assertOk()->assertJsonCount(3, 'tasks');
    }

    public function test_setting_the_template_again_replaces_it_wholesale(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $product = Product::factory()->for($company)->create();

        $this->putJson("/api/catalog/{$product->id}/implementation-template", [
            'tasks' => [['task_name' => 'A'], ['task_name' => 'B']],
        ], $this->headers($token))->assertOk();

        $response = $this->putJson("/api/catalog/{$product->id}/implementation-template", [
            'tasks' => [['task_name' => 'C']],
        ], $this->headers($token));

        $response->assertOk()->assertJsonCount(1, 'tasks');
        $this->assertSame('C', $response->json('tasks.0.task_name'));
        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'product_implementation_template', 'action' => 'updated',
        ]);
    }

    public function test_template_for_a_product_in_another_company_is_not_found(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $token = $this->ownerToken($companyA);
        $foreignProduct = Product::factory()->for($companyB)->create();

        $this->putJson("/api/catalog/{$foreignProduct->id}/implementation-template", [
            'tasks' => [['task_name' => 'X']],
        ], $this->headers($token))->assertStatus(404);
    }

    // ---- Job Order multi-Product selection + template import ------------

    private function customerAndContract(Company $company): array
    {
        $customer = CompanyIndividual::factory()->for($company)->create();
        $contract = Contract::factory()->for($company)->create(['customer_id' => $customer->id]);

        return [$customer, $contract];
    }

    public function test_selecting_a_product_on_a_new_job_order_imports_its_template_tasks(): void
    {
        $company = Company::factory()->create();
        [$customer, $contract] = $this->customerAndContract($company);
        $token = $this->ownerToken($company);
        $product = Product::factory()->for($company)->create();
        $this->putJson("/api/catalog/{$product->id}/implementation-template", [
            'tasks' => [['task_name' => 'Site survey'], ['task_name' => 'Install hardware']],
        ], $this->headers($token))->assertOk();

        $response = $this->postJson('/api/job-orders', [
            'customer_id' => $customer->id,
            'contract_id' => $contract->id,
            'subject' => 'New install',
            'product_ids' => [$product->id],
        ], $this->headers($token));

        $response->assertOk();
        $this->assertCount(1, $response->json('products'));
        $this->assertSame($product->id, $response->json('products.0.product_id'));
        $this->assertCount(2, $response->json('implementation_tasks'));
        $this->assertSame('Site survey', $response->json('implementation_tasks.0.task_name'));
        $this->assertSame('pending', $response->json('implementation_tasks.0.status'));
    }

    public function test_multiple_products_import_dedupes_identically_named_tasks(): void
    {
        // Documents the dedupe choice -- see
        // App\Services\JobOrderImplementationTaskService's class
        // docblock: identically-named tasks across selected products'
        // templates are copied only once (first selection wins).
        $company = Company::factory()->create();
        [$customer, $contract] = $this->customerAndContract($company);
        $token = $this->ownerToken($company);
        $productA = Product::factory()->for($company)->create(['name' => 'Product A']);
        $productB = Product::factory()->for($company)->create(['name' => 'Product B']);
        $this->putJson("/api/catalog/{$productA->id}/implementation-template", [
            'tasks' => [['task_name' => 'Site survey'], ['task_name' => 'A-only task']],
        ], $this->headers($token))->assertOk();
        $this->putJson("/api/catalog/{$productB->id}/implementation-template", [
            'tasks' => [['task_name' => 'site survey'], ['task_name' => 'B-only task']], // same name, different case
        ], $this->headers($token))->assertOk();

        $response = $this->postJson('/api/job-orders', [
            'customer_id' => $customer->id,
            'contract_id' => $contract->id,
            'subject' => 'Combined install',
            'product_ids' => [$productA->id, $productB->id],
        ], $this->headers($token));

        $response->assertOk();
        $names = collect($response->json('implementation_tasks'))->pluck('task_name')->all();
        $this->assertCount(3, $names); // "Site survey" once, plus each product's own unique task
        $this->assertContains('Site survey', $names);
        $this->assertContains('A-only task', $names);
        $this->assertContains('B-only task', $names);
    }

    public function test_job_order_is_rejected_for_a_customer_not_on_the_contract_or_its_shared_hours_list(): void
    {
        $company = Company::factory()->create();
        $contractCustomer = CompanyIndividual::factory()->for($company)->create();
        $contract = Contract::factory()->for($company)->create(['customer_id' => $contractCustomer->id]);
        $otherCustomer = CompanyIndividual::factory()->for($company)->create();
        $token = $this->ownerToken($company);

        $response = $this->postJson('/api/job-orders', [
            'customer_id' => $otherCustomer->id,
            'contract_id' => $contract->id,
            'subject' => 'Should be rejected',
        ], $this->headers($token));

        $response->assertStatus(422);
    }

    public function test_only_sales_manager_or_owner_can_complete_an_implementation_task(): void
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
        $jobOrder = JobOrder::factory()->for($company)->create(['customer_id' => $customer->id, 'contract_id' => $contract->id]);
        $task = $jobOrder->implementationTasks()->create(['task_name' => 'Install hardware', 'sort_order' => 0]);

        $this->postJson(
            "/api/job-orders/{$jobOrder->id}/implementation-tasks/{$task->id}/complete", [], $this->headers($login->json('access_token')),
        )->assertStatus(403);

        $ownerToken = $this->ownerToken($company);
        $this->postJson(
            "/api/job-orders/{$jobOrder->id}/implementation-tasks/{$task->id}/complete", [], $this->headers($ownerToken),
        )->assertOk()->assertJson(['status' => 'completed']);
    }
}
