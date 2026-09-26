<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API-level coverage of App\Http\Controllers\Api\QuotationController --
 * RBAC/multi-company/audit, per docs/php-conversion-plan.md's "after
 * converting each module" checklist. Business-rule arithmetic and the
 * accept -> auto-Contract conversion are covered separately in
 * QuotationServiceTest.php.
 */
class QuotationTest extends TestCase
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

    public function test_owner_can_create_and_list_quotations(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $token = $this->ownerToken($company);

        $create = $this->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'quotation_date' => '2026-09-14',
            'lines' => [
                ['description' => 'On-site support', 'unit_of_measure' => 'Hours', 'quantity' => 10, 'unit_price_sgd' => 300],
            ],
        ], $this->headers($token));

        $create->assertOk()->assertJson(['status' => 'draft', 'amount_sgd' => 3000.0, 'total_amount_sgd' => 3000.0]);
        $this->assertStringStartsWith('QUO-', $create->json('quotation_number'));
        $this->assertCount(1, $create->json('lines'));

        $this->getJson('/api/quotations', $this->headers($token))->assertOk()->assertJsonCount(1)
            // The Mobile App's quotation cards show it (2026-09-26).
            ->assertJsonPath('0.customer_name', $customer->name);
        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'quotation', 'entity_id' => $create->json('id'), 'action' => 'created',
        ]);
    }

    public function test_create_with_no_lines_returns_422(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $token = $this->ownerToken($company);

        $response = $this->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'quotation_date' => '2026-09-14',
        ], $this->headers($token));

        $response->assertStatus(422);
        $this->assertSame('A quotation needs at least one line.', $response->json('detail'));
    }

    public function test_line_defaults_from_the_chosen_products_reference_code_and_cost(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $product = Product::factory()->for($company)->create(['sales_price_sgd' => 500, 'cost_sgd' => 200]);
        $token = $this->ownerToken($company);

        $create = $this->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'quotation_date' => '2026-09-14',
            'lines' => [
                ['product_id' => $product->id, 'description' => $product->name, 'quantity' => 1, 'unit_price_sgd' => 500],
            ],
        ], $this->headers($token));

        $create->assertOk();
        $this->assertEqualsWithDelta(200.0, $create->json('lines.0.cost_sgd'), 0.01);
    }

    public function test_send_then_accept_converts_hourly_lines_to_a_contract(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $token = $this->ownerToken($company);

        $create = $this->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'quotation_date' => '2026-09-14',
            'lines' => [
                ['description' => 'On-site support', 'unit_of_measure' => 'Hours', 'quantity' => 10, 'unit_price_sgd' => 300],
            ],
        ], $this->headers($token));
        $id = $create->json('id');

        // BILL-006 (settled 2026-09-15): submit -> approve -> send -> accept.
        $this->postJson("/api/quotations/{$id}/submit", [], $this->headers($token))
            ->assertOk()->assertJson(['status' => 'pending_approval']);
        $this->postJson("/api/quotations/{$id}/approve", [], $this->headers($token))
            ->assertOk()->assertJson(['status' => 'approved']);
        $this->postJson("/api/quotations/{$id}/send", [], $this->headers($token))
            ->assertOk()->assertJson(['status' => 'sent']);

        $accept = $this->postJson("/api/quotations/{$id}/accept", [], $this->headers($token));
        $accept->assertOk();
        $this->assertSame('accepted', $accept->json('quotation.status'));
        $this->assertNotNull($accept->json('quotation.converted_contract_id'));
        $this->assertStringContainsString('Service Support contract created (10 hrs).', $accept->json('message'));
    }

    public function test_accept_twice_returns_409(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $token = $this->ownerToken($company);
        $quotation = Quotation::factory()->for($company)->create(['customer_id' => $customer->id, 'status' => Quotation::STATUS_ACCEPTED]);

        $response = $this->postJson("/api/quotations/{$quotation->id}/accept", [], $this->headers($token));

        $response->assertStatus(409);
        $this->assertStringContainsString('A accepted quotation cannot be accepted.', $response->json('detail'));
    }

    public function test_reject_a_draft_quotation(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $token = $this->ownerToken($company);
        $quotation = Quotation::factory()->for($company)->create(['customer_id' => $customer->id]);

        $this->postJson("/api/quotations/{$quotation->id}/reject", [], $this->headers($token))
            ->assertOk()->assertJson(['status' => 'rejected']);

        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'quotation', 'entity_id' => $quotation->id, 'action' => 'rejected',
        ]);
    }

    public function test_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/quotations', $this->headers($login->json('access_token')))->assertStatus(403);
    }

    public function test_view_only_group_cannot_create(): void
    {
        $company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'sales'], ['name' => 'Sales', 'is_built' => true]);
        CompanyModule::create(['company_id' => $company->id, 'module_key' => 'sales', 'enabled' => true]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'sales', 'access_level' => GroupModuleAuthority::VIEW]);
        $staff = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        UserCompanyAccess::create(['user_id' => $staff->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $login = $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234']);
        $token = $login->json('access_token');

        $this->getJson('/api/quotations', $this->headers($token))->assertOk();
        $this->postJson('/api/quotations', [], $this->headers($token))->assertStatus(403);
    }

    public function test_disabled_module_is_denied_even_with_full_group_authority(): void
    {
        $company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'sales'], ['name' => 'Sales', 'is_built' => true]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'sales', 'access_level' => GroupModuleAuthority::FULL]);
        // No CompanyModule row at all -- Module Control fails closed.
        $staff = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        UserCompanyAccess::create(['user_id' => $staff->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $login = $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234']);

        $this->getJson('/api/quotations', $this->headers($login->json('access_token')))->assertStatus(403);
    }

    public function test_quotation_from_another_company_is_not_found(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $token = $this->ownerToken($companyA);
        $foreignQuotation = Quotation::factory()->for($companyB)->create([
            'customer_id' => CompanyIndividual::factory()->for($companyB)->create()->id,
        ]);

        $this->getJson("/api/quotations/{$foreignQuotation->id}", $this->headers($token))->assertStatus(404);
    }

    public function test_customer_from_another_company_is_rejected_on_create(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $foreignCustomer = CompanyIndividual::factory()->for($companyB)->create();
        $token = $this->ownerToken($companyA);

        $response = $this->postJson('/api/quotations', [
            'customer_id' => $foreignCustomer->id,
            'quotation_date' => '2026-09-14',
            'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price_sgd' => 10]],
        ], $this->headers($token));

        $response->assertStatus(404);
    }

    public function test_money_fields_serialize_as_numbers_not_strings(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $token = $this->ownerToken($company);

        $response = $this->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'quotation_date' => '2026-09-14',
            'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price_sgd' => 500]],
        ], $this->headers($token));

        $this->assertMatchesRegularExpression('/"total_amount_sgd":500(\.0)?[,}]/', $response->getContent());
    }
}
