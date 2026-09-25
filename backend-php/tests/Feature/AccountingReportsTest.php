<?php

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\CommissionSettings;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Contract;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\Invoice;
use App\Models\ModuleCatalog;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API-level coverage of App\Http\Controllers\Api\ReportController --
 * the `/accounting/*` half of backend/app/routers/reports.py: AR/AP
 * aging, the trial balance, the GST return, Sales GP and Commission,
 * each with its CSV and XLSX export.
 *
 * See that class's docblock for why the trial balance exists here
 * alongside LedgerController::trialBalance() rather than reusing its
 * route.
 */
class AccountingReportsTest extends TestCase
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

    public function test_owner_can_view_the_trial_balance_report(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $response = $this->getJson('/api/reports/accounting/trial-balance', $this->headers($token));

        $response->assertOk()->assertJson(['is_balanced' => true, 'rows' => []]);
    }

    /**
     * This route is gated by the `accounting_reports` module key, NOT
     * `finance_accounting` (see ReportController's FINDING docblock)
     * -- a Group with FULL authority on finance_accounting alone must
     * still be denied here.
     */
    public function test_finance_accounting_authority_alone_does_not_grant_this_report(): void
    {
        $company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'finance_accounting'], ['name' => 'Finance / Accounting', 'is_built' => true]);
        CompanyModule::updateOrCreate(['company_id' => $company->id, 'module_key' => 'finance_accounting'], ['enabled' => true]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'finance_accounting', 'access_level' => GroupModuleAuthority::FULL]);
        $user = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        UserCompanyAccess::create(['user_id' => $user->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/reports/accounting/trial-balance', $this->headers($login->json('access_token')))
            ->assertStatus(403);
    }

    public function test_accounting_reports_authority_grants_this_report(): void
    {
        $company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'accounting_reports'], ['name' => 'Accounting Reports', 'is_built' => true]);
        CompanyModule::updateOrCreate(['company_id' => $company->id, 'module_key' => 'accounting_reports'], ['enabled' => true]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'accounting_reports', 'access_level' => GroupModuleAuthority::VIEW]);
        $user = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        UserCompanyAccess::create(['user_id' => $user->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/reports/accounting/trial-balance', $this->headers($login->json('access_token')))
            ->assertOk();
    }

    public function test_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/reports/accounting/trial-balance', $this->headers($login->json('access_token')))
            ->assertStatus(403);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function invoice(Company $company, CompanyIndividual $customer, array $attributes = []): Invoice
    {
        $issuedAt = $attributes['issued_at'] ?? now();
        unset($attributes['issued_at']);
        $invoice = Invoice::create(array_merge([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-'.fake()->unique()->numerify('######'),
            'invoice_type' => Invoice::TYPE_CONTRACT_ANNUAL,
            'description' => 'Test invoice',
            'amount_sgd' => '1000.00',
            'tax_code' => 'SR',
            'gst_rate' => '9.00',
            'gst_amount_sgd' => '90.00',
            'total_amount_sgd' => '1090.00',
        ], $attributes));
        // issued_at is not mass-assignable -- and it is the column
        // every period-bounded accounting report filters on.
        $invoice->forceFill(['issued_at' => $issuedAt])->save();

        return $invoice->refresh();
    }

    // ── AR / AP aging ───────────────────────────────────────────────

    public function test_ar_aging_buckets_by_how_overdue_each_invoice_is(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create(['name' => 'Acme Pte Ltd']);
        $this->invoice($company, $customer, [
            'amount_sgd' => '100.00', 'gst_amount_sgd' => '0.00', 'total_amount_sgd' => '100.00',
            'due_date' => now()->addDays(10)->toDateString(),
        ]);
        $this->invoice($company, $customer, [
            'amount_sgd' => '200.00', 'gst_amount_sgd' => '0.00', 'total_amount_sgd' => '200.00',
            'due_date' => now()->subDays(100)->toDateString(),
        ]);

        $response = $this->getJson('/api/reports/accounting/ar-aging', $this->headers($token));

        $response->assertOk();
        $this->assertSame('Acme Pte Ltd', $response->json('rows.0.customer_name'));
        $this->assertEquals(100, $response->json('rows.0.current'));
        $this->assertEquals(200, $response->json('rows.0.over_90'));
        $this->assertEquals(300, $response->json('total'));
    }

    public function test_ar_aging_matches_the_accounts_receivable_screens_own_figures(): void
    {
        // The two screens read the same service, so this asserts they
        // cannot drift -- the property Python's duplicated bucketing
        // loop is there to preserve.
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create();
        $this->invoice($company, $customer, ['due_date' => now()->subDays(45)->toDateString()]);

        $fromReports = $this->getJson('/api/reports/accounting/ar-aging', $this->headers($token))->assertOk()->json();
        $fromArScreen = $this->getJson('/api/accounts-receivable/aging', $this->headers($token))->assertOk()->json();

        // The report adds which company each row came from (it can span
        // several); the figures themselves must still be identical.
        unset($fromReports['companies']);
        $fromReports['rows'] = array_map(function (array $r) {
            unset($r['company_id'], $r['company_name']);

            return $r;
        }, $fromReports['rows']);
        $this->assertSame($fromArScreen, $fromReports);
    }

    public function test_ap_aging_reports_supplier_bills(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $supplier = CompanyIndividual::factory()->for($company)->create([
            'name' => 'Parts Supplier Pte Ltd', 'is_supplier' => true,
        ]);
        SupplierInvoice::factory()->for($company)->create([
            'supplier_id' => $supplier->id,
            'amount_sgd' => '500.00', 'gst_amount_sgd' => '0.00', 'total_amount_sgd' => '500.00',
            'due_date' => now()->subDays(40)->toDateString(),
        ]);

        $response = $this->getJson('/api/reports/accounting/ap-aging', $this->headers($token));

        $response->assertOk();
        $this->assertSame('Parts Supplier Pte Ltd', $response->json('rows.0.supplier_name'));
        $this->assertEquals(500, $response->json('rows.0.days_31_60'));
        $this->assertEquals(500, $response->json('total'));
    }

    // ── GST Return ──────────────────────────────────────────────────

    public function test_gst_return_splits_output_tax_per_code_and_input_tax_as_one_total(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create();
        $supplier = CompanyIndividual::factory()->for($company)->create(['is_supplier' => true]);
        $this->invoice($company, $customer, ['tax_code' => 'SR', 'gst_amount_sgd' => '90.00']);
        $this->invoice($company, $customer, [
            'tax_code' => 'ZR', 'amount_sgd' => '500.00', 'gst_amount_sgd' => '0.00',
            'total_amount_sgd' => '500.00',
        ]);
        SupplierInvoice::factory()->for($company)->create([
            'supplier_id' => $supplier->id, 'amount_sgd' => '200.00', 'gst_amount_sgd' => '18.00',
            'total_amount_sgd' => '218.00', 'invoice_date' => now()->toDateString(),
        ]);

        $response = $this->getJson($this->periodUrl('gst-return'), $this->headers($token));

        $response->assertOk();
        $output = collect($response->json('output_rows'))->keyBy('tax_code');
        $this->assertEqualsWithDelta(90, $output['SR']['tax_sgd'], 0.001);
        $this->assertEqualsWithDelta(0, $output['ZR']['tax_sgd'], 0.001);
        // A supplier bill carries no tax code of its own, so input tax
        // is one PURCHASES line rather than a per-code breakdown.
        $this->assertCount(1, $response->json('input_rows'));
        $this->assertSame('PURCHASES', $response->json('input_rows.0.tax_code'));
        $this->assertEqualsWithDelta(90, $response->json('total_output_tax_sgd'), 0.001);
        $this->assertEqualsWithDelta(18, $response->json('total_input_tax_sgd'), 0.001);
        $this->assertEqualsWithDelta(72, $response->json('net_gst_payable_sgd'), 0.001);
    }

    public function test_gst_return_needs_both_period_bounds(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $this->getJson('/api/reports/accounting/gst-return', $this->headers($token))->assertStatus(422);
    }

    public function test_gst_return_export_tags_each_row_with_its_direction(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create();
        $supplier = CompanyIndividual::factory()->for($company)->create(['is_supplier' => true]);
        $this->invoice($company, $customer);
        SupplierInvoice::factory()->for($company)->create(['supplier_id' => $supplier->id]);

        $body = $this->get($this->periodUrl('gst-return/export.csv'), $this->headers($token))
            ->assertOk()->getContent();

        $this->assertStringContainsString('tax_code,net_sgd,tax_sgd,document_count,direction', $body);
        $this->assertStringContainsString(',output', $body);
        $this->assertStringContainsString('PURCHASES,1000,90,1,input', $body);
    }

    // ── Sales GP ────────────────────────────────────────────────────

    public function test_sales_gp_treats_a_missing_cost_as_zero_and_says_so(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create(['name' => 'Acme Pte Ltd']);
        $this->invoice($company, $customer, ['amount_sgd' => '1000.00', 'cost_sgd' => '400.00']);
        $this->invoice($company, $customer, ['amount_sgd' => '500.00', 'cost_sgd' => null]);

        $response = $this->getJson($this->periodUrl('sales-gp'), $this->headers($token));

        $response->assertOk();
        $rows = collect($response->json('rows'))->keyBy('revenue_sgd');
        $this->assertEqualsWithDelta(600, $rows[1000]['gp_sgd'], 0.001);
        $this->assertEqualsWithDelta(60, $rows[1000]['gp_percent'], 0.001);
        $this->assertTrue($rows[1000]['has_cost_basis']);
        // A null cost reads as zero cost, i.e. 100% GP -- and the row
        // is flagged so a reader knows it is a stand-in, not a
        // measured margin.
        $this->assertEqualsWithDelta(500, $rows[500]['gp_sgd'], 0.001);
        $this->assertFalse($rows[500]['has_cost_basis']);
        $this->assertEqualsWithDelta(1500, $response->json('total_revenue_sgd'), 0.001);
        $this->assertEqualsWithDelta(1100, $response->json('total_gp_sgd'), 0.001);
        $this->assertSame('Acme Pte Ltd', $response->json('rows.0.customer_name'));
    }

    public function test_sales_gp_only_covers_invoices_issued_inside_the_period(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create();
        $this->invoice($company, $customer, ['issued_at' => now()->subYear()]);

        $this->getJson($this->periodUrl('sales-gp'), $this->headers($token))
            ->assertOk()->assertJsonCount(0, 'rows');
    }

    public function test_sales_gp_export_carries_the_issue_date_only(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create(['name' => 'Acme Pte Ltd']);
        $this->invoice($company, $customer, ['amount_sgd' => '1000.00', 'cost_sgd' => '400.00']);

        $body = $this->get($this->periodUrl('sales-gp/export.csv'), $this->headers($token))
            ->assertOk()->getContent();

        $this->assertStringContainsString('invoice_number,issued_date,customer_name', $body);
        // DD/MM/YYYY and the date alone -- no time, no ISO form (2026-09-25).
        $this->assertMatchesRegularExpression('#,'.preg_quote(now()->format('d/m/Y'), '#').',#', $body);
        $this->assertStringNotContainsString(now()->toDateString(), $body);
        $this->assertStringNotContainsString('T00:', $body);
    }

    // ── Commission ──────────────────────────────────────────────────

    public function test_commission_rate_is_zero_until_an_administrator_sets_one(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $this->getJson('/api/reports/accounting/commission-settings', $this->headers($token))
            ->assertOk()->assertJson(['rate_percent' => 0]);
    }

    public function test_setting_the_commission_rate_is_audited(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $this->putJson('/api/reports/accounting/commission-settings', ['rate_percent' => 5], $this->headers($token))
            ->assertOk()->assertJson(['rate_percent' => 5]);

        $this->assertEquals('5.00', CommissionSettings::find($company->id)->rate_percent);
        $entry = AuditLogEntry::where('entity_type', 'commission_settings')->latest('at')->first();
        $this->assertNotNull($entry);
        $this->assertSame('updated', $entry->action);
        // assertEquals: a 0.0 round-trips through JSON as 0.
        $this->assertEquals(['rate_percent' => 0.0], json_decode((string) $entry->old_value, true));
        $this->assertEquals(['rate_percent' => 5.0], json_decode((string) $entry->new_value, true));

        // Second edit records the previous rate, not zero again. The
        // entry is found by its new value rather than by being the
        // newest: `at` is second-resolution, so two edits in one test
        // tie.
        $this->putJson('/api/reports/accounting/commission-settings', ['rate_percent' => 7.5], $this->headers($token))
            ->assertOk();
        $second = AuditLogEntry::where('entity_type', 'commission_settings')
            ->where('new_value', 'like', '%7.5%')->firstOrFail();
        $this->assertEquals(['rate_percent' => 5.0], json_decode((string) $second->old_value, true));
    }

    public function test_commission_rate_outside_zero_to_one_hundred_is_rejected(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $this->putJson('/api/reports/accounting/commission-settings', ['rate_percent' => 101], $this->headers($token))
            ->assertStatus(422);
        $this->putJson('/api/reports/accounting/commission-settings', ['rate_percent' => -1], $this->headers($token))
            ->assertStatus(422);
    }

    public function test_commission_is_rate_times_gp_on_the_settled_share_of_an_invoice(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create();
        $salesStaff = User::factory()->for($company)->create(['full_name' => 'Chan Mei Ling']);
        $contract = Contract::factory()->for($company)->create([
            'customer_id' => $customer->id, 'sales_staff_id' => $salesStaff->id,
        ]);
        // Net 1000, GST 90, total 1090; cost 400 so GP is 60%.
        $invoice = $this->invoice($company, $customer, [
            'contract_id' => $contract->id, 'amount_sgd' => '1000.00', 'gst_amount_sgd' => '90.00',
            'total_amount_sgd' => '1090.00', 'cost_sgd' => '400.00',
        ]);
        $payment = Payment::factory()->for($company)->create([
            'customer_id' => $customer->id, 'payment_date' => now()->toDateString(), 'amount_sgd' => '545.00',
        ]);
        PaymentAllocation::create([
            'company_id' => $company->id, 'payment_id' => $payment->id,
            'invoice_id' => $invoice->id, 'amount_sgd' => '545.00',
        ]);
        CommissionSettings::create(['company_id' => $company->id, 'rate_percent' => '10.00']);

        $response = $this->getJson($this->periodUrl('commission'), $this->headers($token));

        $response->assertOk();
        $this->assertEqualsWithDelta(10, $response->json('rate_percent'), 0.001);
        $this->assertSame(now()->format('Y-m'), $response->json('rows.0.month'));
        $this->assertSame('Chan Mei Ling', $response->json('rows.0.sales_staff_name'));
        // Half the total settled -> half the net revenue (500) -> 60%
        // GP (300) -> 10% commission (30). GST never inflates it.
        $this->assertEqualsWithDelta(30, $response->json('rows.0.commission_sgd'), 0.001);
        $this->assertEqualsWithDelta(30, $response->json('total_commission_sgd'), 0.001);
    }

    public function test_commission_is_zero_while_no_rate_is_set(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create();
        $invoice = $this->invoice($company, $customer, ['cost_sgd' => '400.00']);
        $payment = Payment::factory()->for($company)->create([
            'customer_id' => $customer->id, 'payment_date' => now()->toDateString(),
        ]);
        PaymentAllocation::create([
            'company_id' => $company->id, 'payment_id' => $payment->id,
            'invoice_id' => $invoice->id, 'amount_sgd' => '1090.00',
        ]);

        $response = $this->getJson($this->periodUrl('commission'), $this->headers($token));

        $response->assertOk();
        $this->assertEqualsWithDelta(0, $response->json('total_commission_sgd'), 0.001);
        // An invoice with no contract credits nobody, but is still
        // reported rather than dropped.
        $this->assertSame('Unassigned', $response->json('rows.0.sales_staff_name'));
    }

    public function test_commission_ignores_receipts_outside_the_period(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create();
        $invoice = $this->invoice($company, $customer, ['cost_sgd' => '0.00']);
        $payment = Payment::factory()->for($company)->create([
            'customer_id' => $customer->id, 'payment_date' => now()->subYear()->toDateString(),
        ]);
        PaymentAllocation::create([
            'company_id' => $company->id, 'payment_id' => $payment->id,
            'invoice_id' => $invoice->id, 'amount_sgd' => '1090.00',
        ]);
        CommissionSettings::create(['company_id' => $company->id, 'rate_percent' => '10.00']);

        $this->getJson($this->periodUrl('commission'), $this->headers($token))
            ->assertOk()->assertJsonCount(0, 'rows');
    }

    // ── Exports and access ──────────────────────────────────────────

    public function test_every_accounting_export_is_audited_and_downloadable(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create();
        $this->invoice($company, $customer, ['due_date' => now()->subDays(5)->toDateString()]);

        $cases = [
            ['ar-aging', 'Accounting Report: AR Aging', false],
            ['ap-aging', 'Accounting Report: AP Aging', false],
            ['trial-balance', 'Accounting Report: Trial Balance', false],
            ['gst-return', 'Accounting Report: GST Return', true],
            ['sales-gp', 'Accounting Report: Sales GP', true],
            ['commission', 'Accounting Report: Commission', true],
        ];

        foreach ($cases as [$path, $reportName, $needsPeriod]) {
            foreach (['csv', 'xlsx'] as $format) {
                $url = $needsPeriod
                    ? $this->periodUrl("{$path}/export.{$format}")
                    : "/api/reports/accounting/{$path}/export.{$format}";
                $response = $this->get($url, $this->headers($token))->assertOk();
                $this->assertStringContainsString(
                    $format === 'csv' ? 'text/csv' : 'spreadsheetml',
                    $response->headers->get('Content-Type'),
                    "{$path} {$format}",
                );
                // Matched rather than read off the newest row: `at`
                // is second-resolution, and a loop this tight writes
                // several entries inside one second.
                $expected = "{$reportName} exported as ".strtoupper($format === 'xlsx' ? 'excel' : $format);
                $this->assertTrue(
                    AuditLogEntry::where('entity_type', 'report')
                        ->where('details', 'like', $expected.'%')->exists(),
                    "no audit entry for {$path} {$format}",
                );
            }
        }
    }

    public function test_reading_the_commission_rate_does_not_let_a_view_only_group_change_it(): void
    {
        $company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'accounting_reports'], ['name' => 'Accounting Reports', 'is_built' => true]);
        CompanyModule::updateOrCreate(
            ['company_id' => $company->id, 'module_key' => 'accounting_reports'],
            ['enabled' => true],
        );
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create([
            'group_id' => $group->id, 'module_key' => 'accounting_reports',
            'access_level' => GroupModuleAuthority::VIEW,
        ]);
        $staff = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        UserCompanyAccess::create(['user_id' => $staff->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $token = $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234'])
            ->json('access_token');

        $this->getJson('/api/reports/accounting/commission-settings', $this->headers($token))->assertOk();
        $this->putJson('/api/reports/accounting/commission-settings', ['rate_percent' => 5], $this->headers($token))
            ->assertStatus(403);
    }

    public function test_another_companys_figures_never_appear(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $token = $this->ownerToken($companyA);
        $customerB = CompanyIndividual::factory()->for($companyB)->create();
        $this->invoice($companyB, $customerB, ['due_date' => now()->subDays(5)->toDateString()]);

        $this->getJson('/api/reports/accounting/ar-aging', $this->headers($token))
            ->assertOk()->assertJsonCount(0, 'rows');
        $this->getJson($this->periodUrl('sales-gp'), $this->headers($token))
            ->assertOk()->assertJsonCount(0, 'rows');
    }

    // ── Company / party filters (2026-09-24) ────────────────────────

    public function test_one_report_can_cover_several_of_the_users_companies(): void
    {
        $companyA = Company::factory()->create(['code' => 'C001', 'name' => 'Alpha']);
        $companyB = Company::factory()->create(['code' => 'C002', 'name' => 'Beta']);
        $token = $this->ownerToken($companyA);
        $this->invoice($companyA, CompanyIndividual::factory()->for($companyA)->create(), [
            'amount_sgd' => '100.00', 'gst_amount_sgd' => '0.00', 'total_amount_sgd' => '100.00',
            'due_date' => now()->subDays(5)->toDateString(),
        ]);
        $this->invoice($companyB, CompanyIndividual::factory()->for($companyB)->create(), [
            'amount_sgd' => '250.00', 'gst_amount_sgd' => '0.00', 'total_amount_sgd' => '250.00',
            'due_date' => now()->subDays(5)->toDateString(),
        ]);

        $both = $this->getJson("/api/reports/accounting/ar-aging?company_ids={$companyA->id},{$companyB->id}", $this->headers($token))
            ->assertOk()->assertJsonCount(2, 'rows');
        $this->assertEquals(350, $both->json('total'));
        $this->assertEqualsCanonicalizing(['C001 Alpha', 'C002 Beta'], array_column($both->json('rows'), 'company_name'));

        $onlyB = $this->getJson("/api/reports/accounting/ar-aging?company_ids={$companyB->id}", $this->headers($token))
            ->assertOk()->assertJsonCount(1, 'rows');
        $this->assertEquals(250, $onlyB->json('total'));

        $gp = $this->getJson($this->periodUrl('sales-gp')."&company_ids={$companyA->id},{$companyB->id}", $this->headers($token))
            ->assertOk()->assertJsonCount(2, 'rows');
        $this->assertEquals(350, $gp->json('total_revenue_sgd'));
    }

    public function test_the_export_gains_a_company_column_only_when_several_companies_are_in_it(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $token = $this->ownerToken($companyA);

        $single = $this->get($this->periodUrl('sales-gp/export.csv'), $this->headers($token))->assertOk()->getContent();
        $this->assertStringContainsString('invoice_number,', strtok($single, "\n"));
        $this->assertStringNotContainsString('company_name', strtok($single, "\n"));

        $multi = $this->get($this->periodUrl('sales-gp/export.csv')."&company_ids={$companyA->id},{$companyB->id}", $this->headers($token))->assertOk()->getContent();
        $this->assertStringContainsString('company_name,invoice_number,', strtok($multi, "\n"));
    }

    public function test_a_company_the_user_cannot_switch_to_is_refused(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'accounting_reports'], ['name' => 'Accounting Reports', 'is_built' => true]);
        CompanyModule::updateOrCreate(['company_id' => $company->id, 'module_key' => 'accounting_reports'], ['enabled' => true]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'accounting_reports', 'access_level' => GroupModuleAuthority::VIEW]);
        $user = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        UserCompanyAccess::create(['user_id' => $user->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $token = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234'])->json('access_token');

        $this->getJson("/api/reports/accounting/ar-aging?company_ids={$company->id}", $this->headers($token))->assertOk();
        $this->getJson("/api/reports/accounting/ar-aging?company_ids={$company->id},{$other->id}", $this->headers($token))->assertStatus(403);
        $this->get("/api/reports/accounting/ar-aging/export.csv?company_ids={$other->id}", $this->headers($token))->assertStatus(403);
    }

    public function test_customer_filter_narrows_ar_aging_to_the_chosen_customers(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $acme = CompanyIndividual::factory()->for($company)->create(['name' => 'Acme']);
        $beta = CompanyIndividual::factory()->for($company)->create(['name' => 'Beta']);
        $this->invoice($company, $acme, ['due_date' => now()->subDays(5)->toDateString()]);
        $this->invoice($company, $beta, ['due_date' => now()->subDays(5)->toDateString()]);

        $response = $this->getJson("/api/reports/accounting/ar-aging?customer_ids={$beta->id}", $this->headers($token))
            ->assertOk()->assertJsonCount(1, 'rows');
        $this->assertSame('Beta', $response->json('rows.0.customer_name'));
    }

    public function test_filter_options_list_every_company_individual_across_the_selected_companies(): void
    {
        $companyA = Company::factory()->create(['code' => 'C001']);
        $companyB = Company::factory()->create(['code' => 'C002']);
        $token = $this->ownerToken($companyA);
        // One list whatever the flags: a customer can also be a supplier.
        CompanyIndividual::factory()->for($companyA)->create(['name' => 'Acme', 'is_customer' => true, 'is_supplier' => false]);
        CompanyIndividual::factory()->for($companyB)->create(['name' => 'Bolt', 'is_customer' => false, 'is_supplier' => true]);

        $both = $this->getJson("/api/reports/accounting/filter-options?company_ids={$companyA->id},{$companyB->id}", $this->headers($token))->assertOk();
        $this->assertSame(['Acme (C001)', 'Bolt (C002)'], array_column($both->json('company_individuals'), 'name'));

        $one = $this->getJson("/api/reports/accounting/filter-options?company_ids={$companyA->id}", $this->headers($token))->assertOk();
        $this->assertSame(['Acme'], array_column($one->json('company_individuals'), 'name'));
    }

    private function periodUrl(string $path): string
    {
        return "/api/reports/accounting/{$path}?period_start=".now()->startOfMonth()->toDateString()
            .'&period_end='.now()->endOfMonth()->toDateString();
    }
}
