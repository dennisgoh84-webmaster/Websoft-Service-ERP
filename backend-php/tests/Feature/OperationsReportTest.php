<?php

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Contract;
use App\Models\ContractProduct;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\JobOrder;
use App\Models\ModuleCatalog;
use App\Models\Product;
use App\Models\ServiceRecord;
use App\Models\SetupListItem;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Operations Reports -- the `/reports/operations/*` half of
 * backend/app/routers/reports.py, converted to PHP/Laravel.
 *
 * Covers the four reports, their filters, the CSV and XLSX exports,
 * the audit entry every export writes, and the two access rules
 * (Module Control + Group Authority, company scoping).
 */
class OperationsReportTest extends TestCase
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

    // ── Contracts ───────────────────────────────────────────────────

    public function test_contracts_report_returns_the_contract_record_shape(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create();
        $contract = Contract::factory()->for($company)->create([
            'customer_id' => $customer->id,
            'contracted_minutes' => 600,
            'consumed_minutes' => 120,
        ]);

        $response = $this->getJson('/api/reports/operations/contracts', $this->headers($token));

        $response->assertOk()->assertJsonCount(1);
        // The screen types this as Contract[] and calls
        // `contracted_hours.toFixed(1)`, so hours must be numbers here
        // -- the formatted strings belong to the export rows only.
        $this->assertSame($contract->id, $response->json('0.id'));
        $this->assertSame($customer->id, $response->json('0.customer_id'));
        // assertEquals, not assertSame: PHP's `/` returns an int for
        // an exact division, so 600 minutes serialises as 10, not 10.0
        // -- the same JSON the Contracts screen already reads.
        $this->assertEquals(10, $response->json('0.contracted_hours'));
        $this->assertEquals(2, $response->json('0.consumed_hours'));
        $this->assertEquals(8, $response->json('0.remaining_hours'));
    }

    public function test_contracts_report_filters_by_status_customer_and_expiry_window(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customerA = CompanyIndividual::factory()->for($company)->create();
        $customerB = CompanyIndividual::factory()->for($company)->create();
        $soon = Contract::factory()->for($company)->create([
            'customer_id' => $customerA->id,
            'status' => Contract::STATUS_ACTIVE,
            'end_date' => now()->addDays(10)->toDateString(),
        ]);
        $later = Contract::factory()->for($company)->create([
            'customer_id' => $customerA->id,
            'status' => Contract::STATUS_ACTIVE,
            'end_date' => now()->addDays(300)->toDateString(),
        ]);
        $otherCustomer = Contract::factory()->for($company)->create([
            'customer_id' => $customerB->id,
            'status' => Contract::STATUS_ACTIVE,
            'end_date' => now()->addDays(10)->toDateString(),
        ]);
        $alreadyExpired = Contract::factory()->for($company)->create([
            'customer_id' => $customerA->id,
            'status' => Contract::STATUS_EXPIRED,
            'end_date' => now()->subDays(5)->toDateString(),
        ]);

        $ids = collect($this->getJson(
            '/api/reports/operations/contracts?customer_id='.$customerA->id.'&expiring_within_days=30',
            $this->headers($token),
        )->assertOk()->json())->pluck('id')->all();

        $this->assertContains($soon->id, $ids);
        $this->assertNotContains($later->id, $ids);
        $this->assertNotContains($otherCustomer->id, $ids);
        // "Expiring within N days" is forward-looking: something that
        // expired last week is not about to expire.
        $this->assertNotContains($alreadyExpired->id, $ids);

        $statusIds = collect($this->getJson(
            '/api/reports/operations/contracts?status='.Contract::STATUS_EXPIRED,
            $this->headers($token),
        )->assertOk()->json())->pluck('id')->all();
        $this->assertSame([$alreadyExpired->id], $statusIds);
    }

    public function test_contracts_report_takes_several_companies_individuals_at_once(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        [$a, $b, $c] = CompanyIndividual::factory()->for($company)->count(3)->create()->all();
        $forA = Contract::factory()->for($company)->create(['customer_id' => $a->id]);
        $forB = Contract::factory()->for($company)->create(['customer_id' => $b->id]);
        $forC = Contract::factory()->for($company)->create(['customer_id' => $c->id]);

        $ids = collect($this->getJson(
            "/api/reports/operations/contracts?customer_ids={$a->id},{$b->id}",
            $this->headers($token),
        )->assertOk()->json())->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$forA->id, $forB->id], $ids);
        $this->assertNotContains($forC->id, $ids);
    }

    public function test_contracts_csv_export_carries_the_customer_name_and_formatted_hours(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create(['name' => 'Acme Pte Ltd']);
        $contract = Contract::factory()->for($company)->create([
            'customer_id' => $customer->id,
            'contracted_minutes' => 630,
            'consumed_minutes' => 0,
        ]);

        $response = $this->get('/api/reports/operations/contracts/export.csv', $this->headers($token));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $body = $response->getContent();
        $this->assertStringContainsString('contract_number,customer_name', $body);
        $this->assertStringContainsString($contract->contract_number, $body);
        $this->assertStringContainsString('Acme Pte Ltd', $body);
        $this->assertStringContainsString('10.50', $body);
    }

    public function test_contracts_excel_export_is_a_real_xlsx(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        Contract::factory()->for($company)->create([
            'customer_id' => CompanyIndividual::factory()->for($company)->create(['name' => 'Acme Pte Ltd'])->id,
        ]);

        $response = $this->get('/api/reports/operations/contracts/export.xlsx', $this->headers($token));

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));
        $path = tempnam(sys_get_temp_dir(), 'ops').'.xlsx';
        file_put_contents($path, $response->getContent());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        // PhpSpreadsheet writes cell text into the shared string
        // table, not into the sheet XML.
        $this->assertStringContainsString('Acme Pte Ltd', $zip->getFromName('xl/sharedStrings.xml'));
        $zip->close();
        unlink($path);
    }

    // ── Job Orders ──────────────────────────────────────────────────

    public function test_job_orders_report_filters_to_overdue_only(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create();
        $overdue = JobOrder::factory()->for($company)->create([
            'customer_id' => $customer->id,
            'due_date' => now()->subDays(3)->toDateString(),
            'status' => JobOrder::STATUS_OPEN,
        ]);
        $closedButPastDue = JobOrder::factory()->for($company)->create([
            'customer_id' => $customer->id,
            'due_date' => now()->subDays(3)->toDateString(),
            'status' => JobOrder::STATUS_CLOSED,
        ]);
        $notDueYet = JobOrder::factory()->for($company)->create([
            'customer_id' => $customer->id,
            'due_date' => now()->addDays(3)->toDateString(),
            'status' => JobOrder::STATUS_OPEN,
        ]);

        $ids = collect($this->getJson(
            '/api/reports/operations/job-orders?overdue_only=true',
            $this->headers($token),
        )->assertOk()->json())->pluck('id')->all();

        $this->assertSame([$overdue->id], $ids);
        $this->assertNotContains($closedButPastDue->id, $ids);
        $this->assertNotContains($notDueYet->id, $ids);
    }

    public function test_job_orders_csv_names_the_assignee_and_flags_overdue(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $assignee = User::factory()->for($company)->create(['full_name' => 'Siti Rahman']);
        JobOrder::factory()->for($company)->create([
            'customer_id' => CompanyIndividual::factory()->for($company)->create(['name' => 'Acme Pte Ltd'])->id,
            'assigned_to_user_id' => $assignee->id,
            'due_date' => now()->subDays(2)->toDateString(),
            'status' => JobOrder::STATUS_OPEN,
        ]);

        $body = $this->get('/api/reports/operations/job-orders/export.csv', $this->headers($token))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Siti Rahman', $body);
        $this->assertStringContainsString('Acme Pte Ltd', $body);
        $this->assertStringContainsString(',Yes,', $body);
    }

    public function test_job_order_assignee_from_another_company_still_resolves_by_name(): void
    {
        // A staff member reaches a company through UserCompanyAccess,
        // so a name lookup scoped to the `users` table's own company
        // column would blank this one out. Python loads every user for
        // exactly this reason.
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $token = $this->ownerToken($company);
        $assignee = User::factory()->for($otherCompany)->create(['full_name' => 'Wei Lin']);
        UserCompanyAccess::create([
            'user_id' => $assignee->id,
            'company_id' => $company->id,
            'group_id' => Group::factory()->for($company)->create()->id,
        ]);
        JobOrder::factory()->for($company)->create([
            'customer_id' => CompanyIndividual::factory()->for($company)->create()->id,
            'assigned_to_user_id' => $assignee->id,
        ]);

        $body = $this->get('/api/reports/operations/job-orders/export.csv', $this->headers($token))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Wei Lin', $body);
    }

    // ── Service Records ─────────────────────────────────────────────

    public function test_service_records_report_filters_through_the_job_orders_customer(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customerA = CompanyIndividual::factory()->for($company)->create();
        $customerB = CompanyIndividual::factory()->for($company)->create();
        $employee = User::factory()->for($company)->create(['full_name' => 'Kumar S']);
        $jobA = JobOrder::factory()->for($company)->create(['customer_id' => $customerA->id]);
        $jobB = JobOrder::factory()->for($company)->create(['customer_id' => $customerB->id]);
        $recordA = ServiceRecord::factory()->for($company)->create([
            'job_order_id' => $jobA->id, 'employee_user_id' => $employee->id, 'rounded_minutes' => 90,
        ]);
        ServiceRecord::factory()->for($company)->create([
            'job_order_id' => $jobB->id, 'employee_user_id' => $employee->id,
        ]);

        $response = $this->getJson(
            '/api/reports/operations/service-records?customer_id='.$customerA->id,
            $this->headers($token),
        );

        $response->assertOk()->assertJsonCount(1);
        $this->assertSame($recordA->id, $response->json('0.id'));
        // Minutes, which the screen divides itself -- the formatted
        // hours belong to the export rows.
        $this->assertSame(90, $response->json('0.rounded_minutes'));
    }

    public function test_service_records_csv_resolves_the_customer_through_the_job_order(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create(['name' => 'Beta Holdings']);
        $employee = User::factory()->for($company)->create(['full_name' => 'Kumar S']);
        $job = JobOrder::factory()->for($company)->create(['customer_id' => $customer->id]);
        ServiceRecord::factory()->for($company)->create([
            'job_order_id' => $job->id, 'employee_user_id' => $employee->id, 'rounded_minutes' => 90,
        ]);

        $body = $this->get('/api/reports/operations/service-records/export.csv', $this->headers($token))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Beta Holdings', $body);
        $this->assertStringContainsString('Kumar S', $body);
        $this->assertStringContainsString('1.50', $body);
    }

    // ── Customer Product Usage ──────────────────────────────────────

    public function test_customer_product_usage_lists_one_row_per_customer_and_product(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        SetupListItem::create([
            'list_type' => SetupListItem::TYPE_INDUSTRY, 'code' => 'FNB', 'name' => 'Food & Beverage',
        ]);
        $customer = CompanyIndividual::factory()->for($company)->create([
            'name' => 'Acme Pte Ltd', 'industry_code' => 'FNB',
        ]);
        $contract = Contract::factory()->for($company)->create(['customer_id' => $customer->id]);
        $productOne = Product::factory()->for($company)->create(['name' => 'Payroll Module']);
        $productTwo = Product::factory()->for($company)->create(['name' => 'Inventory Module']);
        ContractProduct::create(['contract_id' => $contract->id, 'product_id' => $productOne->id]);
        ContractProduct::create(['contract_id' => $contract->id, 'product_id' => $productTwo->id]);

        $response = $this->getJson('/api/reports/operations/customer-product-usage', $this->headers($token));

        $response->assertOk()->assertJsonCount(2);
        $this->assertSame('Acme Pte Ltd', $response->json('0.customer_name'));
        $this->assertSame('Food & Beverage', $response->json('0.industry_name'));
        // Ordered by customer name, then product name.
        $this->assertSame('Inventory Module', $response->json('0.product_name'));
        $this->assertSame('Payroll Module', $response->json('1.product_name'));

        $filtered = $this->getJson(
            '/api/reports/operations/customer-product-usage?product_id='.$productOne->id,
            $this->headers($token),
        );
        $filtered->assertOk()->assertJsonCount(1);
        $this->assertSame('Payroll Module', $filtered->json('0.product_name'));
    }

    // ── Audit ───────────────────────────────────────────────────────

    public function test_every_export_is_audited_with_its_format_and_row_count(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        Contract::factory()->for($company)->create([
            'customer_id' => CompanyIndividual::factory()->for($company)->create()->id,
        ]);

        $this->get('/api/reports/operations/contracts/export.csv', $this->headers($token))->assertOk();

        // Matched rather than read off the newest row: `at` is
        // second-resolution, so two exports in one test tie.
        $this->assertTrue($this->exportWasAudited('Operations Report: Contracts exported as CSV (1 row)'));

        Contract::factory()->for($company)->create([
            'customer_id' => CompanyIndividual::factory()->for($company)->create()->id,
        ]);
        $this->get('/api/reports/operations/contracts/export.xlsx', $this->headers($token))->assertOk();

        $this->assertTrue($this->exportWasAudited('Operations Report: Contracts exported as EXCEL (2 rows)'));
    }

    private function exportWasAudited(string $details): bool
    {
        return AuditLogEntry::where('entity_type', 'report')->where('details', $details)->exists();
    }

    // ── Access ──────────────────────────────────────────────────────

    public function test_module_control_disabled_blocks_a_group_authorised_user(): void
    {
        $company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'operations_reports'], ['name' => 'Operations Reports', 'is_built' => true]);
        CompanyModule::create(['company_id' => $company->id, 'module_key' => 'operations_reports', 'enabled' => false]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create([
            'group_id' => $group->id, 'module_key' => 'operations_reports',
            'access_level' => GroupModuleAuthority::FULL,
        ]);
        $staff = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        UserCompanyAccess::create(['user_id' => $staff->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $login = $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234']);

        foreach (['contracts', 'job-orders', 'service-records', 'customer-product-usage'] as $report) {
            $this->getJson("/api/reports/operations/{$report}", $this->headers($login->json('access_token')))
                ->assertStatus(403);
        }
    }

    public function test_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/reports/operations/contracts', $this->headers($login->json('access_token')))
            ->assertStatus(403);
    }

    public function test_another_companys_rows_never_appear(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $token = $this->ownerToken($companyA);
        $customerB = CompanyIndividual::factory()->for($companyB)->create();
        Contract::factory()->for($companyB)->create(['customer_id' => $customerB->id]);
        JobOrder::factory()->for($companyB)->create(['customer_id' => $customerB->id]);

        $this->getJson('/api/reports/operations/contracts', $this->headers($token))->assertOk()->assertJsonCount(0);
        $this->getJson('/api/reports/operations/job-orders', $this->headers($token))->assertOk()->assertJsonCount(0);
        $this->getJson('/api/reports/operations/customer-product-usage', $this->headers($token))
            ->assertOk()->assertJsonCount(0);
    }

    // ── Internal Companies (2026-09-25) ─────────────────────────────

    public function test_operations_reports_can_cover_several_of_the_users_companies(): void
    {
        $companyA = Company::factory()->create(['code' => 'C001', 'name' => 'Alpha']);
        $companyB = Company::factory()->create(['code' => 'C002', 'name' => 'Beta']);
        $token = $this->ownerToken($companyA);
        $acme = CompanyIndividual::factory()->for($companyA)->create(['name' => 'Acme']);
        $bolt = CompanyIndividual::factory()->for($companyB)->create(['name' => 'Bolt']);
        Contract::factory()->for($companyA)->create(['customer_id' => $acme->id]);
        Contract::factory()->for($companyB)->create(['customer_id' => $bolt->id]);
        JobOrder::factory()->for($companyA)->create(['customer_id' => $acme->id]);
        JobOrder::factory()->for($companyB)->create(['customer_id' => $bolt->id]);

        // Nothing sent = the signed-in company only.
        $this->getJson('/api/reports/operations/contracts', $this->headers($token))->assertOk()->assertJsonCount(1);

        $both = "company_ids={$companyA->id},{$companyB->id}";
        $contracts = $this->getJson("/api/reports/operations/contracts?{$both}", $this->headers($token))->assertOk()->assertJsonCount(2);
        $this->assertEqualsCanonicalizing(['C001 Alpha', 'C002 Beta'], array_column($contracts->json(), 'company_name'));
        $this->assertEqualsCanonicalizing(['Acme', 'Bolt'], array_column($contracts->json(), 'customer_name'));

        $orders = $this->getJson("/api/reports/operations/job-orders?{$both}", $this->headers($token))->assertOk()->assertJsonCount(2);
        $this->assertEqualsCanonicalizing(['Acme', 'Bolt'], array_column($orders->json(), 'customer_name'));

        $csv = $this->get("/api/reports/operations/contracts/export.csv?{$both}", $this->headers($token))->assertOk()->getContent();
        $this->assertStringStartsWith('company_name,contract_number,', $csv);
        $single = $this->get('/api/reports/operations/contracts/export.csv', $this->headers($token))->assertOk()->getContent();
        $this->assertStringStartsWith('contract_number,', $single);
    }

    public function test_operations_filter_options_span_the_selected_companies(): void
    {
        $companyA = Company::factory()->create(['code' => 'C001']);
        $companyB = Company::factory()->create(['code' => 'C002']);
        $token = $this->ownerToken($companyA);
        CompanyIndividual::factory()->for($companyA)->create(['name' => 'Acme']);
        CompanyIndividual::factory()->for($companyB)->create(['name' => 'Bolt']);

        $both = $this->getJson("/api/reports/operations/filter-options?company_ids={$companyA->id},{$companyB->id}", $this->headers($token))->assertOk();
        $this->assertSame(['Acme (C001)', 'Bolt (C002)'], array_column($both->json('company_individuals'), 'name'));
        $one = $this->getJson('/api/reports/operations/filter-options', $this->headers($token))->assertOk();
        $this->assertSame(['Acme'], array_column($one->json('company_individuals'), 'name'));
    }

    public function test_operations_reports_refuse_a_company_the_user_cannot_switch_to(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'operations_reports'], ['name' => 'Operations Reports', 'is_built' => true]);
        CompanyModule::updateOrCreate(['company_id' => $company->id, 'module_key' => 'operations_reports'], ['enabled' => true]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'operations_reports', 'access_level' => GroupModuleAuthority::VIEW]);
        $user = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        UserCompanyAccess::create(['user_id' => $user->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $token = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234'])->json('access_token');

        $this->getJson("/api/reports/operations/job-orders?company_ids={$company->id}", $this->headers($token))->assertOk();
        $this->getJson("/api/reports/operations/job-orders?company_ids={$company->id},{$other->id}", $this->headers($token))->assertStatus(403);
        $this->getJson("/api/reports/operations/contracts/expiry-listing?company_ids={$other->id}", $this->headers($token))->assertStatus(403);
    }
}
