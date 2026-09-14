<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Contract;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\Product;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API-level coverage of App\Http\Controllers\Api\ContractController --
 * RBAC/multi-company/audit, per docs/php-conversion-plan.md's "after
 * converting each module" checklist. Business-rule arithmetic is
 * covered separately in ContractServiceTest.php.
 */
class ContractTest extends TestCase
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

    public function test_owner_can_create_and_list_contracts(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $token = $this->ownerToken($company);

        $create = $this->postJson('/api/contracts', [
            'customer_id' => $customer->id,
            'contracted_hours' => 10,
            'contract_value_sgd' => 3000,
            'start_date' => '2026-01-01',
        ], $this->headers($token));

        $create->assertOk()->assertJson(['status' => 'draft', 'contracted_hours' => 10.0]);
        $this->assertStringStartsWith('CON-', $create->json('contract_number'));

        $this->getJson('/api/contracts', $this->headers($token))->assertOk()->assertJsonCount(1);
    }

    public function test_below_minimum_hours_returns_422_with_srv_002_message(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $token = $this->ownerToken($company);

        $response = $this->postJson('/api/contracts', [
            'customer_id' => $customer->id,
            'contracted_hours' => 5,
            'contract_value_sgd' => 1000,
            'start_date' => '2026-01-01',
        ], $this->headers($token));

        $response->assertStatus(422);
        $this->assertStringContainsString('SRV-002', $response->json('detail'));
    }

    public function test_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/contracts', $this->headers($login->json('access_token')))->assertStatus(403);
    }

    public function test_view_only_group_cannot_activate(): void
    {
        $company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'service_contracts'], ['name' => 'Service Contracts', 'is_built' => true]);
        CompanyModule::create(['company_id' => $company->id, 'module_key' => 'service_contracts', 'enabled' => true]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'service_contracts', 'access_level' => GroupModuleAuthority::VIEW]);
        $staff = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        UserCompanyAccess::create(['user_id' => $staff->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $login = $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234']);
        $token = $login->json('access_token');

        $contract = Contract::factory()->for($company)->create(['customer_id' => CompanyIndividual::factory()->for($company)->create()->id]);

        $this->getJson("/api/contracts/{$contract->id}", $this->headers($token))->assertOk();
        $this->postJson("/api/contracts/{$contract->id}/activate", [], $this->headers($token))->assertStatus(403);
    }

    public function test_contract_from_another_company_is_not_found(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $token = $this->ownerToken($companyA);
        $foreignContract = Contract::factory()->for($companyB)->create([
            'customer_id' => CompanyIndividual::factory()->for($companyB)->create()->id,
        ]);

        $this->getJson("/api/contracts/{$foreignContract->id}", $this->headers($token))->assertStatus(404);
    }

    public function test_activate_writes_audit_trail(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $contract = Contract::factory()->for($company)->create([
            'customer_id' => CompanyIndividual::factory()->for($company)->create()->id,
        ]);

        $this->postJson("/api/contracts/{$contract->id}/activate", [], $this->headers($token))
            ->assertOk()->assertJson(['status' => 'active']);

        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'contract', 'entity_id' => $contract->id, 'action' => 'activated',
        ]);
    }

    public function test_renew_beyond_window_without_force_date_returns_422(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $contract = Contract::factory()->for($company)->create([
            'customer_id' => CompanyIndividual::factory()->for($company)->create()->id,
            'status' => Contract::STATUS_ACTIVE,
            'end_date' => now()->subDays(60)->toDateString(),
        ]);

        $response = $this->postJson("/api/contracts/{$contract->id}/renew", [
            'contracted_hours' => 10, 'contract_value_sgd' => 3000,
        ], $this->headers($token));

        $response->assertStatus(422);
        $this->assertStringContainsString('SRV-018', $response->json('detail'));
    }

    public function test_update_replaces_product_coverage(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $contract = Contract::factory()->for($company)->create([
            'customer_id' => CompanyIndividual::factory()->for($company)->create()->id,
        ]);
        $product = Product::factory()->for($company)->create();

        $response = $this->patchJson("/api/contracts/{$contract->id}", [
            'product_ids' => [$product->id],
        ], $this->headers($token));

        $response->assertOk();
        $this->assertCount(1, $response->json('products'));
        $this->assertSame($product->id, $response->json('products.0.product_id'));
    }

    public function test_money_fields_serialize_as_numbers_not_strings(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $token = $this->ownerToken($company);

        $response = $this->postJson('/api/contracts', [
            'customer_id' => $customer->id,
            'contracted_hours' => 10,
            'contract_value_sgd' => 3000,
            'start_date' => '2026-01-01',
        ], $this->headers($token));

        $this->assertMatchesRegularExpression('/"contract_value_sgd":3000(\.0)?[,}]/', $response->getContent());
    }
}
