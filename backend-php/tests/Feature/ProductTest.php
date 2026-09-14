<?php

namespace Tests\Feature;

use App\Models\Company;
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
 * Mirrors backend/app/routers/catalog.py -- see
 * docs/php-conversion-plan.md's "after converting each module"
 * checklist, which this test file follows.
 */
class ProductTest extends TestCase
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

    public function test_owner_can_create_and_list_products(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $create = $this->postJson('/api/catalog', [
            'name' => 'Websoft Implementation',
            'product_type' => 'service',
            'sales_price_sgd' => 3000,
        ], $this->headers($token));

        $create->assertOk()->assertJson(['name' => 'Websoft Implementation', 'sales_price_sgd' => 3000.0]);
        // The JSON wire format is a bare number, not a numeric string --
        // matches Python's ProductOut schema (float), see
        // docs/php-conversion-plan.md's Decimal/money handling
        // convention. (PHP's json_decode collapses a whole-number
        // float like 3000.0 back to an int, same as JS -- so this
        // checks the raw response body for the absence of quotes
        // around the value, not PHP's own decoded type.)
        $this->assertMatchesRegularExpression('/"sales_price_sgd":3000(\.0)?[,}]/', $create->getContent());

        $this->getJson('/api/catalog', $this->headers($token))->assertOk()->assertJsonCount(1);
    }

    public function test_defaults_match_python_when_omitted(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $response = $this->postJson('/api/catalog', ['name' => 'Minimal Item'], $this->headers($token));

        $response->assertOk()->assertJson([
            'product_type' => 'service', // ProductType.service default
            'sales_price_sgd' => 0.0,
            'tax_code' => 'SR', // DEFAULT_TAX_CODE
            'is_stock' => false,
            'is_active' => true,
        ]);
    }

    public function test_gated_by_sales_module_not_company_individual_management(): void
    {
        $company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'sales'], ['name' => 'Sales', 'is_built' => true]);
        ModuleCatalog::firstOrCreate(['key' => 'company_individual_management'], ['name' => 'Customer Management', 'is_built' => true]);
        $group = Group::factory()->for($company)->create();
        // FULL on company_individual_management, nothing on sales.
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'company_individual_management', 'access_level' => GroupModuleAuthority::FULL]);
        $staff = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        UserCompanyAccess::create(['user_id' => $staff->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $login = $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234']);

        $this->getJson('/api/catalog', $this->headers($login->json('access_token')))->assertStatus(403);
    }

    public function test_update_ignores_is_stock_same_as_python(): void
    {
        // Documents a real quirk in the Python source: ProductUpdate's
        // schema accepts is_stock, but update_product's field loop
        // never applies it -- see ProductController::UPDATABLE_FIELDS's
        // comment. This pins that the PHP port matches, rather than
        // silently "fixing" behaviour on our own judgement.
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $product = Product::factory()->for($company)->create(['is_stock' => false]);

        $response = $this->patchJson("/api/catalog/{$product->id}", ['is_stock' => true], $this->headers($token));

        $response->assertOk()->assertJson(['is_stock' => false]);
        $this->assertFalse($product->fresh()->is_stock);
    }

    public function test_update_writes_audit_trail_with_price_change(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $product = Product::factory()->for($company)->create(['sales_price_sgd' => '100.00']);

        $response = $this->patchJson("/api/catalog/{$product->id}", ['sales_price_sgd' => 150.5], $this->headers($token));

        $response->assertOk()->assertJson(['sales_price_sgd' => 150.5]);

        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'product',
            'entity_id' => $product->id,
            'action' => 'updated',
        ]);
    }

    public function test_product_from_another_company_is_not_found(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $token = $this->ownerToken($companyA);
        $foreignProduct = Product::factory()->for($companyB)->create();

        $this->patchJson("/api/catalog/{$foreignProduct->id}", ['name' => 'X'], $this->headers($token))
            ->assertStatus(404);
    }
}
