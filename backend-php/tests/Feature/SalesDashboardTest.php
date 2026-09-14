<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\Invoice;
use App\Models\ModuleCatalog;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\Numbering;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NEW FEATURE (not a Python->PHP conversion -- see
 * docs/backlog.md / docs/planned-work.md): "Sales Dashboard". API-
 * level coverage of App\Http\Controllers\Api\SalesDashboardController
 * -- business-rule arithmetic (FY boundary, aging buckets, Top/Bottom
 * 10) is pinned separately in SalesDashboardServiceTest.php.
 */
class SalesDashboardTest extends TestCase
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

    public function test_summary_returns_all_kpi_tiles(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $response = $this->getJson('/api/sales-dashboard/summary', $this->headers($token));

        $response->assertOk()->assertJsonStructure([
            'financial_year', 'financial_year_is_calendar_year',
            'contracts_due_for_renewal', 'ar_outstanding_total_sgd',
            'ar_outstanding_2_months_sgd', 'ar_outstanding_3_months_sgd',
            'quotations_pending_approval' => ['count', 'not_available'],
            'quotations_pending_confirmation' => ['count', 'not_available'],
        ]);
        $this->assertTrue($response->json('quotations_pending_approval.not_available'));
    }

    public function test_top_billing_customers_drill_down_list(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create();
        Invoice::create([
            'company_id' => $company->id, 'customer_id' => $customer->id,
            'invoice_number' => Numbering::next($company->id, 'invoice'), 'invoice_type' => Invoice::TYPE_CONTRACT_ANNUAL,
            'description' => 'x', 'amount_sgd' => 5000, 'gst_rate' => 0, 'gst_amount_sgd' => 0, 'total_amount_sgd' => 5000,
            'issued_at' => now(),
        ]);

        $response = $this->getJson('/api/sales-dashboard/top-billing-customers?year='.now()->year, $this->headers($token));

        $response->assertOk()->assertJsonCount(1);
        $this->assertSame($customer->id, $response->json('0.customer_id'));
        $this->assertEqualsWithDelta(5000.0, $response->json('0.net_revenue_sgd'), 0.01);
    }

    public function test_ar_breakdown_drill_down_export_csv(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $response = $this->get('/api/sales-dashboard/ar-breakdown/export.csv', $this->headers($token));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    public function test_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/sales-dashboard/summary', $this->headers($login->json('access_token')))->assertStatus(403);
    }

    public function test_module_control_disabled_blocks_summary(): void
    {
        $company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'reporting'], ['name' => 'Reporting', 'is_built' => true]);
        CompanyModule::create(['company_id' => $company->id, 'module_key' => 'reporting', 'enabled' => false]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'reporting', 'access_level' => GroupModuleAuthority::FULL]);
        $staff = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        UserCompanyAccess::create(['user_id' => $staff->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $login = $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234']);

        $this->getJson('/api/sales-dashboard/summary', $this->headers($login->json('access_token')))->assertStatus(403);
    }

    public function test_figures_never_leak_across_companies(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $token = $this->ownerToken($companyA);
        $customerB = CompanyIndividual::factory()->for($companyB)->create();
        Invoice::create([
            'company_id' => $companyB->id, 'customer_id' => $customerB->id,
            'invoice_number' => Numbering::next($companyB->id, 'invoice'), 'invoice_type' => Invoice::TYPE_CONTRACT_ANNUAL,
            'description' => 'x', 'amount_sgd' => 9999, 'gst_rate' => 0, 'gst_amount_sgd' => 0, 'total_amount_sgd' => 9999,
            'issued_at' => now(),
        ]);

        $response = $this->getJson('/api/sales-dashboard/top-billing-customers?year='.now()->year, $this->headers($token));

        $response->assertOk()->assertJsonCount(0);
    }
}
