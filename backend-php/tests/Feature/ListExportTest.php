<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Contract;
use App\Models\ContractProduct;
use App\Models\ExcessUsageRecord;
use App\Models\Group;
use App\Models\Invoice;
use App\Models\JobOrder;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\ServiceRecord;
use App\Models\SetupListItem;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The CSV/Excel Export buttons on the list screens --
 * `export.csv`/`export.xlsx` on nearly every Python router, the last
 * export format that was missing from `backend-php` (the .docx/PDF/
 * Email half landed with the document generation stack).
 *
 * What each case asserts is the property that actually matters: the
 * export returns WHAT IS ON SCREEN. Each one applies a filter, then
 * checks the row it excludes is absent from the file as well as the
 * row it includes being present -- a generic "did it download"
 * assertion would pass even if the export quietly ignored every
 * filter.
 */
class ListExportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $owner = User::factory()->for($this->company)->create([
            'role' => User::ROLE_OWNER,
            'full_name' => 'Owner Person',
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $this->token = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234'])
            ->json('access_token');
    }

    private function csv(string $path): string
    {
        $response = $this->get($path, ['Authorization' => "Bearer {$this->token}"]);
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        return $response->getContent();
    }

    /**
     * Data rows in a CSV, excluding the header. Used where a document
     * number cannot identify a row: the factories allocate numbers
     * against a throwaway company, so two rows in one test can legally
     * share one.
     */
    private function dataRowCount(string $csv): int
    {
        return count(array_filter(explode("\r\n", trim($csv)))) - 1;
    }

    private function assertXlsxContains(string $path, string $needle): void
    {
        $response = $this->get($path, ['Authorization' => "Bearer {$this->token}"]);
        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));
        $file = tempnam(sys_get_temp_dir(), 'exp').'.xlsx';
        file_put_contents($file, $response->getContent());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($file) === true);
        // PhpSpreadsheet writes cell text into the shared string table.
        $this->assertStringContainsString($needle, $zip->getFromName('xl/sharedStrings.xml'));
        $zip->close();
        unlink($file);
    }

    public function test_chart_of_accounts_export_honours_the_include_inactive_filter(): void
    {
        Account::create([
            'company_id' => $this->company->id, 'code' => '9001',
            'name' => 'Retired Account', 'account_type' => 'expense', 'is_active' => false,
        ]);
        Account::create([
            'company_id' => $this->company->id, 'code' => '9002',
            'name' => 'Live Account', 'account_type' => 'expense',
        ]);

        $body = $this->csv('/api/accounts/export.csv');
        $this->assertStringContainsString('code,name,account_type,description,is_active', $body);
        $this->assertStringContainsString('Live Account', $body);
        $this->assertStringNotContainsString('Retired Account', $body);

        $this->assertStringContainsString(
            'Retired Account',
            $this->csv('/api/accounts/export.csv?include_inactive=true'),
        );
        $this->assertXlsxContains('/api/accounts/export.xlsx', 'Live Account');
    }

    public function test_bank_accounts_export_names_the_gl_account_by_its_code(): void
    {
        $gl = Account::create([
            'company_id' => $this->company->id, 'code' => '1060',
            'name' => 'Bank - DBS', 'account_type' => 'asset',
        ]);
        BankAccount::create([
            'company_id' => $this->company->id, 'bank_name' => 'DBS Bank',
            'account_name' => 'Webmaster Operating', 'account_number' => '003-123456-7',
            'currency_code' => 'SGD', 'gl_account_id' => $gl->id,
        ]);

        $body = $this->csv('/api/bank-accounts/export.csv');
        $this->assertStringContainsString('DBS Bank', $body);
        // The GL account's CODE, not its uuid -- a uuid means nothing
        // to whoever opens the spreadsheet.
        $this->assertStringContainsString('1060', $body);
        $this->assertXlsxContains('/api/bank-accounts/export.xlsx', 'Webmaster Operating');
    }

    public function test_catalog_export_formats_money_to_two_decimals_and_blanks_an_unknown_cost(): void
    {
        Product::factory()->for($this->company)->create([
            'name' => 'Support Retainer', 'sales_price_sgd' => '1500', 'cost_sgd' => null,
        ]);

        $body = $this->csv('/api/catalog/export.csv');
        $this->assertStringContainsString('Support Retainer', $body);
        $this->assertStringContainsString('1500.00', $body);
        // A null cost exports blank, not "0.00" -- unknown is not zero.
        $this->assertMatchesRegularExpression('/1500\.00,,/', $body);
        $this->assertXlsxContains('/api/catalog/export.xlsx', 'Support Retainer');
    }

    public function test_company_individual_export_flattens_the_address_and_names_group_and_industry(): void
    {
        SetupListItem::create([
            'list_type' => SetupListItem::TYPE_INDUSTRY, 'code' => 'FNB', 'name' => 'Food & Beverage',
        ]);
        CompanyIndividual::factory()->for($this->company)->create([
            'name' => 'Acme Pte Ltd', 'industry_code' => 'FNB',
            'address_line1' => '10 Anson Road', 'address_city' => 'Singapore',
            'address_postal_code' => '079903', 'address_country' => 'Singapore',
        ]);
        CompanyIndividual::factory()->for($this->company)->create(['name' => 'Beta Holdings']);

        $body = $this->csv('/api/company-individuals/export.csv?q=Acme');
        $this->assertStringContainsString('Acme Pte Ltd', $body);
        $this->assertStringContainsString('Food & Beverage', $body);
        $this->assertStringContainsString('10 Anson Road, Singapore, 079903, Singapore', $body);
        // The filter reached the export, not just the screen.
        $this->assertStringNotContainsString('Beta Holdings', $body);
        $this->assertXlsxContains('/api/company-individuals/export.xlsx', 'Acme Pte Ltd');
    }

    public function test_groups_export_counts_members(): void
    {
        $group = Group::factory()->for($this->company)->create(['name' => 'Finance Team']);
        $member = User::factory()->for($this->company)->create();
        UserCompanyAccess::create([
            'user_id' => $member->id, 'company_id' => $this->company->id, 'group_id' => $group->id,
        ]);

        $body = $this->csv('/api/groups/export.csv');
        $this->assertStringContainsString('name,description,member_count', $body);
        $this->assertStringContainsString('Finance Team', $body);
        $this->assertStringContainsString(',1', $body);
        $this->assertXlsxContains('/api/groups/export.xlsx', 'Finance Team');
    }

    public function test_users_export_names_the_group_rather_than_its_id(): void
    {
        $group = Group::factory()->for($this->company)->create(['name' => 'Finance Team']);
        $member = User::factory()->for($this->company)->create(['full_name' => 'Siti Rahman']);
        UserCompanyAccess::create([
            'user_id' => $member->id, 'company_id' => $this->company->id, 'group_id' => $group->id,
        ]);
        User::factory()->for($this->company)->create(['full_name' => 'Retired Person', 'is_active' => false]);

        $body = $this->csv('/api/users/export.csv');
        $this->assertStringContainsString('Siti Rahman', $body);
        $this->assertStringContainsString('Finance Team', $body);
        $this->assertStringNotContainsString($group->id, $body);
        $this->assertStringNotContainsString('Retired Person', $body);

        $this->assertStringContainsString(
            'Retired Person',
            $this->csv('/api/users/export.csv?include_inactive=true'),
        );
        $this->assertXlsxContains('/api/users/export.xlsx', 'Siti Rahman');
    }

    public function test_an_export_never_reaches_another_companys_rows(): void
    {
        $otherCompany = Company::factory()->create();
        CompanyIndividual::factory()->for($otherCompany)->create(['name' => 'Someone Elses Customer']);
        Account::create([
            'company_id' => $otherCompany->id, 'code' => '9999',
            'name' => 'Someone Elses Account', 'account_type' => 'expense',
        ]);

        $this->assertStringNotContainsString(
            'Someone Elses Customer',
            $this->csv('/api/company-individuals/export.csv'),
        );
        $this->assertStringNotContainsString('Someone Elses Account', $this->csv('/api/accounts/export.csv'));
    }

    public function test_an_export_is_denied_to_a_user_with_no_group(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $token = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234'])
            ->json('access_token');

        $this->get('/api/accounts/export.csv', ['Authorization' => "Bearer {$token}"])->assertStatus(403);
        $this->get('/api/catalog/export.xlsx', ['Authorization' => "Bearer {$token}"])->assertStatus(403);
    }

    // ── Transactional list screens ──────────────────────────────────

    public function test_contracts_export_names_the_customer_sales_staff_and_products(): void
    {
        $customer = CompanyIndividual::factory()->for($this->company)->create(['name' => 'Acme Pte Ltd']);
        $salesStaff = User::factory()->for($this->company)->create(['full_name' => 'Chan Mei Ling']);
        $contract = Contract::factory()->for($this->company)->create([
            'customer_id' => $customer->id, 'sales_staff_id' => $salesStaff->id,
            'contracted_minutes' => 600, 'consumed_minutes' => 120,
        ]);
        $product = Product::factory()->for($this->company)->create(['name' => 'Payroll Module']);
        ContractProduct::create(['contract_id' => $contract->id, 'product_id' => $product->id]);
        Contract::factory()->for($this->company)->create([
            'customer_id' => CompanyIndividual::factory()->for($this->company)
                ->create(['name' => 'Beta Holdings'])->id,
        ]);

        $body = $this->csv('/api/contracts/export.csv?customer_id='.$customer->id);
        $this->assertStringContainsString('Acme Pte Ltd', $body);
        $this->assertStringContainsString('Chan Mei Ling', $body);
        $this->assertStringContainsString('Payroll Module', $body);
        $this->assertStringContainsString('10.00', $body);
        // The filter reached the export, not just the screen.
        $this->assertStringNotContainsString('Beta Holdings', $body);
        $this->assertSame(1, $this->dataRowCount($body));
        $this->assertXlsxContains('/api/contracts/export.xlsx', 'Acme Pte Ltd');
    }

    public function test_job_orders_export_honours_the_status_filter(): void
    {
        $customer = CompanyIndividual::factory()->for($this->company)->create(['name' => 'Acme Pte Ltd']);
        JobOrder::factory()->for($this->company)->create([
            'customer_id' => $customer->id, 'subject' => 'Server migration', 'status' => JobOrder::STATUS_OPEN,
        ]);
        JobOrder::factory()->for($this->company)->create([
            'customer_id' => $customer->id, 'subject' => 'Printer setup', 'status' => JobOrder::STATUS_CLOSED,
        ]);

        $body = $this->csv('/api/job-orders/export.csv?status='.JobOrder::STATUS_OPEN);
        $this->assertStringContainsString('Server migration', $body);
        $this->assertStringContainsString('Acme Pte Ltd', $body);
        $this->assertStringNotContainsString('Printer setup', $body);
        $this->assertSame(1, $this->dataRowCount($body));
        $this->assertXlsxContains('/api/job-orders/export.xlsx', 'Server migration');
    }

    public function test_service_records_export_blanks_an_undecided_deduction(): void
    {
        $job = JobOrder::factory()->for($this->company)->create([
            'customer_id' => CompanyIndividual::factory()->for($this->company)->create()->id,
            'subject' => 'Server migration',
        ]);
        $employee = User::factory()->for($this->company)->create(['full_name' => 'Kumar S']);
        ServiceRecord::factory()->for($this->company)->create([
            'job_order_id' => $job->id, 'employee_user_id' => $employee->id, 'deducted_minutes' => null,
        ]);

        $body = $this->csv('/api/service-records/export.csv');
        $this->assertStringContainsString('Server migration', $body);
        $this->assertStringContainsString('Kumar S', $body);
        // Undecided is not "nothing deducted", so it exports blank.
        $this->assertStringNotContainsString(',0,C,', $body);
        $this->assertXlsxContains('/api/service-records/export.xlsx', 'Kumar S');
    }

    public function test_excess_usage_export_reaches_the_customer_through_the_contract(): void
    {
        $customer = CompanyIndividual::factory()->for($this->company)->create(['name' => 'Acme Pte Ltd']);
        $contract = Contract::factory()->for($this->company)->create(['customer_id' => $customer->id]);
        $job = JobOrder::factory()->for($this->company)->create(['customer_id' => $customer->id]);
        $record = ServiceRecord::factory()->for($this->company)->create([
            'job_order_id' => $job->id,
            'employee_user_id' => User::factory()->for($this->company)->create()->id,
        ]);
        ExcessUsageRecord::create([
            'company_id' => $this->company->id, 'contract_id' => $contract->id,
            'service_record_id' => $record->id, 'excess_minutes' => 90,
        ]);

        $body = $this->csv('/api/excess-usage/export.csv?pending_only=true');
        $this->assertStringContainsString('customer_name,excess_hours,treatment,reason,invoiced', $body);
        $this->assertStringContainsString('Acme Pte Ltd', $body);
        $this->assertStringContainsString('1.50', $body);
        $this->assertXlsxContains('/api/excess-usage/export.xlsx', 'Acme Pte Ltd');
    }

    private function invoice(CompanyIndividual $customer, array $attributes = []): Invoice
    {
        $invoice = Invoice::create(array_merge([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-'.fake()->unique()->numerify('######'),
            'invoice_type' => Invoice::TYPE_CONTRACT_ANNUAL,
            'description' => 'Annual support',
            'amount_sgd' => '1000.00', 'tax_code' => 'SR', 'gst_rate' => '9.00',
            'gst_amount_sgd' => '90.00', 'total_amount_sgd' => '1090.00',
        ], $attributes));
        $invoice->forceFill(['issued_at' => now()])->save();

        return $invoice;
    }

    public function test_invoices_export_carries_the_outstanding_balance_and_the_issue_date_only(): void
    {
        $customer = CompanyIndividual::factory()->for($this->company)->create(['name' => 'Acme Pte Ltd']);
        $invoice = $this->invoice($customer, ['amount_paid_sgd' => '90.00']);

        $body = $this->csv('/api/invoices/export.csv');
        $this->assertStringContainsString($invoice->invoice_number, $body);
        $this->assertStringContainsString('Acme Pte Ltd', $body);
        $this->assertStringContainsString('1000.00,90.00,1090.00,1000.00', $body);
        $this->assertStringContainsString(now()->toDateString(), $body);
        $this->assertStringNotContainsString('T00:', $body);
        $this->assertXlsxContains('/api/invoices/export.xlsx', 'Acme Pte Ltd');
    }

    public function test_quotations_export_honours_the_status_filter(): void
    {
        $customer = CompanyIndividual::factory()->for($this->company)->create(['name' => 'Acme Pte Ltd']);
        Quotation::factory()->for($this->company)->create([
            'customer_id' => $customer->id, 'status' => Quotation::STATUS_DRAFT,
        ]);
        Quotation::factory()->for($this->company)->create([
            'customer_id' => $customer->id, 'status' => Quotation::STATUS_SENT,
        ]);

        $body = $this->csv('/api/quotations/export.csv?status='.Quotation::STATUS_DRAFT);
        $this->assertStringContainsString(',draft,', $body);
        $this->assertStringNotContainsString(',sent,', $body);
        $this->assertSame(1, $this->dataRowCount($body));
        $this->assertXlsxContains('/api/quotations/export.xlsx', 'Acme Pte Ltd');
    }

    // ── AR / AP ─────────────────────────────────────────────────────

    public function test_ar_aging_export_buckets_by_how_overdue_each_invoice_is(): void
    {
        $customer = CompanyIndividual::factory()->for($this->company)->create(['name' => 'Acme Pte Ltd']);
        $this->invoice($customer, [
            'amount_sgd' => '200.00', 'gst_amount_sgd' => '0.00', 'total_amount_sgd' => '200.00',
            'due_date' => now()->subDays(100)->toDateString(),
        ]);

        $body = $this->csv('/api/accounts-receivable/aging/export.csv');
        $this->assertStringContainsString('customer_name,current,days_1_30', $body);
        $this->assertStringContainsString('Acme Pte Ltd,0.00,0.00,0.00,0.00,200.00,200.00', $body);
        $this->assertXlsxContains('/api/accounts-receivable/aging/export.xlsx', 'Acme Pte Ltd');
    }

    public function test_ar_receipts_export_splits_allocated_from_unallocated(): void
    {
        $customer = CompanyIndividual::factory()->for($this->company)->create(['name' => 'Acme Pte Ltd']);
        $invoice = $this->invoice($customer);
        $payment = Payment::factory()->for($this->company)->create([
            'customer_id' => $customer->id, 'amount_sgd' => '1000.00',
        ]);
        PaymentAllocation::create([
            'company_id' => $this->company->id, 'payment_id' => $payment->id,
            'invoice_id' => $invoice->id, 'amount_sgd' => '400.00',
        ]);

        $body = $this->csv('/api/accounts-receivable/payments/export.csv');
        $this->assertStringContainsString($payment->voucher_number, $body);
        $this->assertStringContainsString('1000.00,400.00,600.00', $body);
        $this->assertXlsxContains('/api/accounts-receivable/payments/export.xlsx', 'Acme Pte Ltd');
    }

    public function test_ap_aging_and_bills_exports(): void
    {
        $supplier = CompanyIndividual::factory()->for($this->company)->create([
            'name' => 'Parts Supplier Pte Ltd', 'is_supplier' => true,
        ]);
        $bill = SupplierInvoice::factory()->for($this->company)->create([
            'supplier_id' => $supplier->id,
            'amount_sgd' => '500.00', 'gst_amount_sgd' => '0.00', 'total_amount_sgd' => '500.00',
            'due_date' => now()->subDays(40)->toDateString(),
        ]);

        $aging = $this->csv('/api/accounts-payable/aging/export.csv');
        $this->assertStringContainsString('supplier_name,current,days_1_30', $aging);
        $this->assertStringContainsString('Parts Supplier Pte Ltd,0.00,0.00,500.00,0.00,0.00,500.00', $aging);

        $bills = $this->csv('/api/accounts-payable/bills/export.csv');
        $this->assertStringContainsString($bill->bill_number, $bills);
        $this->assertStringContainsString('Parts Supplier Pte Ltd', $bills);
        $this->assertStringContainsString('500.00', $bills);

        $this->assertXlsxContains('/api/accounts-payable/aging/export.xlsx', 'Parts Supplier Pte Ltd');
        $this->assertXlsxContains('/api/accounts-payable/bills/export.xlsx', 'Parts Supplier Pte Ltd');
    }

    public function test_purchase_orders_and_supplier_payments_exports(): void
    {
        $supplier = CompanyIndividual::factory()->for($this->company)->create([
            'name' => 'Parts Supplier Pte Ltd', 'is_supplier' => true,
        ]);
        $po = PurchaseOrder::factory()->for($this->company)->create([
            'supplier_id' => $supplier->id, 'description' => 'Replacement switches',
        ]);
        $payment = SupplierPayment::factory()->for($this->company)->create([
            'supplier_id' => $supplier->id, 'amount_sgd' => '300.00',
        ]);

        $pos = $this->csv('/api/accounts-payable/purchase-orders/export.csv');
        $this->assertStringContainsString($po->po_number, $pos);
        $this->assertStringContainsString('Replacement switches', $pos);

        $payments = $this->csv('/api/accounts-payable/payments/export.csv');
        $this->assertStringContainsString($payment->voucher_number, $payments);
        // Nothing allocated yet, so the whole voucher is unallocated.
        $this->assertStringContainsString('300.00,0.00,300.00', $payments);

        $this->assertXlsxContains('/api/accounts-payable/purchase-orders/export.xlsx', 'Replacement switches');
        $this->assertXlsxContains('/api/accounts-payable/payments/export.xlsx', 'Parts Supplier Pte Ltd');
    }
}
