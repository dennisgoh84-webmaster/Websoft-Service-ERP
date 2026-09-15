<?php

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\Invoice;
use App\Models\JobOrder;
use App\Models\ModuleCatalog;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\ServiceRecord;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\BillingService;
use App\Services\ContractService;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API-level coverage of the `.docx` export and "Email X" endpoints
 * retrofitted onto every document module, plus the AR Customer
 * Statement endpoints -- the gaps recorded in
 * docs/php-conversion-plan.md against Service Records, Invoices,
 * Purchase Orders, Quotations and AR statements.
 *
 * Mirrors the export/email routes in backend/app/routers/billing.py,
 * quotations.py, service_records.py, payables.py and
 * accounts_receivable.py.
 *
 * WHAT THE EMAIL TESTS CAN AND CANNOT EXERCISE: there is no SMTP
 * server here, so a successful send cannot be driven end to end. What
 * IS driven end to end is everything up to the socket -- the document
 * is really built, really converted to PDF, really attached -- and the
 * unconfigured-mailer case, which Python treats as a first-class
 * outcome (a 422 with a clear message rather than pretending to have
 * sent). The "no email on file" guard is exercised without needing
 * SMTP or LibreOffice at all, since it short-circuits first.
 */
class DocumentExportTest extends TestCase
{
    use RefreshDatabase;

    private const DOCX_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    protected function setUp(): void
    {
        parent::setUp();
        // No SMTP account configured, matching a stock install --
        // "Email X" must fail loudly, never silently.
        config(['websoft.smtp_host' => null, 'websoft.smtp_from_email' => null]);
    }

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

    private function customer(Company $company, ?string $email = 'ap@acme.example'): CompanyIndividual
    {
        return CompanyIndividual::factory()->for($company)->create([
            'name' => 'Acme Logistics Pte Ltd', 'billing_email' => $email,
        ]);
    }

    private function supplier(Company $company, ?string $email = 'sales@hw.example'): CompanyIndividual
    {
        return CompanyIndividual::factory()->for($company)->create([
            'name' => 'Hardware Supplies Pte Ltd', 'is_supplier' => true, 'billing_email' => $email,
        ]);
    }

    private function invoice(Company $company, User $actor, CompanyIndividual $customer): Invoice
    {
        $contract = ContractService::createContract(
            companyId: $company->id, customerId: $customer->id, contractedHours: 10,
            contractValueSgd: 1000, startDate: now()->toDateString(), actorUserId: $actor->id,
        );

        return BillingService::issueContractAnnualInvoice($contract, $actor->id);
    }

    /** A .docx download: right status, right media type, right filename. */
    private function assertDocxDownload(string $url, string $token, string $expectedFilename): void
    {
        $response = $this->get($url, $this->headers($token));
        $response->assertOk();
        $response->assertHeader('Content-Type', self::DOCX_TYPE);
        $response->assertHeader('Content-Disposition', "attachment; filename={$expectedFilename}");
        // Really a Word package, not an error body served with the
        // right header: PK zip magic plus a readable document part.
        $bytes = $response->getContent();
        $this->assertStringStartsWith('PK', $bytes);
        $path = tempnam(sys_get_temp_dir(), 'dl-');
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $this->assertNotFalse($zip->getFromName('word/document.xml'));
        $zip->close();
        @unlink($path);
    }

    // ---- Sales Invoice ------------------------------------------------

    public function test_invoice_word_export(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        $invoice = $this->invoice($company, $owner, $this->customer($company));

        $this->assertDocxDownload("/api/invoices/{$invoice->id}/export.docx", $token, "{$invoice->invoice_number}.docx");
    }

    public function test_invoice_email_without_an_address_on_file_is_422_and_writes_no_audit_entry(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        $invoice = $this->invoice($company, $owner, $this->customer($company, null));

        $this->postJson("/api/invoices/{$invoice->id}/email", [], $this->headers($token))
            ->assertStatus(422)
            ->assertJson(['detail' => 'This customer has no email on file -- add one on the Company/Individual page first.']);

        $this->assertSame(0, AuditLogEntry::where('action', 'emailed')->count());
    }

    public function test_invoice_email_with_no_smtp_configured_fails_loudly(): void
    {
        $this->requireLibreOffice();
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        $invoice = $this->invoice($company, $owner, $this->customer($company));

        $response = $this->postJson("/api/invoices/{$invoice->id}/email", [], $this->headers($token));

        $response->assertStatus(422);
        $this->assertStringContainsString('Email is not configured for', $response->json('detail'));
        // Nothing was sent, so nothing is recorded as sent.
        $this->assertSame(0, AuditLogEntry::where('action', 'emailed')->count());
    }

    public function test_invoice_export_from_another_company_is_not_found(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        [, $tokenA] = $this->ownerToken($companyA);
        [$ownerB] = $this->ownerToken($companyB);
        $invoiceB = $this->invoice($companyB, $ownerB, $this->customer($companyB));

        $this->getJson("/api/invoices/{$invoiceB->id}/export.docx", $this->headers($tokenA))->assertStatus(404);
        $this->postJson("/api/invoices/{$invoiceB->id}/email", [], $this->headers($tokenA))->assertStatus(404);
    }

    public function test_invoice_export_needs_the_billing_module(): void
    {
        $company = Company::factory()->create();
        [$owner] = $this->ownerToken($company);
        $invoice = $this->invoice($company, $owner, $this->customer($company));
        // FULL Group Authority but no Module Control row -- fails closed.
        ModuleCatalog::firstOrCreate(['key' => 'billing'], ['name' => 'Billing', 'is_built' => true]);
        $token = $this->staffToken($company, 'billing', GroupModuleAuthority::FULL, enabled: false);

        $this->getJson("/api/invoices/{$invoice->id}/export.docx", $this->headers($token))->assertStatus(403);
    }

    public function test_invoice_email_needs_edit_not_just_view(): void
    {
        $company = Company::factory()->create();
        [$owner] = $this->ownerToken($company);
        $invoice = $this->invoice($company, $owner, $this->customer($company));
        ModuleCatalog::firstOrCreate(['key' => 'billing'], ['name' => 'Billing', 'is_built' => true]);
        $token = $this->staffToken($company, 'billing', GroupModuleAuthority::VIEW);

        // VIEW is enough for the Word export...
        $this->get("/api/invoices/{$invoice->id}/export.docx", $this->headers($token))->assertOk();
        // ...but the Python route gates Email at EDIT.
        $this->postJson("/api/invoices/{$invoice->id}/email", [], $this->headers($token))->assertStatus(403);
    }

    // ---- Sales Quotation ----------------------------------------------

    public function test_quotation_word_export_and_email_guard(): void
    {
        $company = Company::factory()->create();
        [, $token] = $this->ownerToken($company);
        $quotation = Quotation::factory()->for($company)->create([
            'customer_id' => $this->customer($company, null)->id,
        ]);

        $this->assertDocxDownload("/api/quotations/{$quotation->id}/export.docx", $token, "{$quotation->quotation_number}.docx");
        $this->postJson("/api/quotations/{$quotation->id}/email", [], $this->headers($token))->assertStatus(422);
    }

    public function test_quotation_export_from_another_company_is_not_found(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        [, $tokenA] = $this->ownerToken($companyA);
        $quotationB = Quotation::factory()->for($companyB)->create([
            'customer_id' => $this->customer($companyB)->id,
        ]);

        $this->getJson("/api/quotations/{$quotationB->id}/export.docx", $this->headers($tokenA))->assertStatus(404);
    }

    // ---- Service Record -----------------------------------------------

    public function test_service_record_word_export_and_email_guard(): void
    {
        $company = Company::factory()->create();
        [, $token] = $this->ownerToken($company);
        $customer = $this->customer($company, null);
        $jobOrder = JobOrder::factory()->for($company)->create(['customer_id' => $customer->id]);
        $record = ServiceRecord::factory()->for($company)->create(['job_order_id' => $jobOrder->id]);

        $this->assertDocxDownload("/api/service-records/{$record->id}/export.docx", $token, "{$record->service_record_number}.docx");
        $this->postJson("/api/service-records/{$record->id}/email", [], $this->headers($token))->assertStatus(422);
    }

    public function test_service_record_export_from_another_company_is_not_found(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        [, $tokenA] = $this->ownerToken($companyA);
        $recordB = ServiceRecord::factory()->for($companyB)->create();

        $this->getJson("/api/service-records/{$recordB->id}/export.docx", $this->headers($tokenA))->assertStatus(404);
    }

    // ---- Purchase Order -----------------------------------------------

    public function test_purchase_order_word_export(): void
    {
        $company = Company::factory()->create();
        [, $token] = $this->ownerToken($company);
        $po = PurchaseOrder::factory()->for($company)->create(['supplier_id' => $this->supplier($company)->id]);

        $this->assertDocxDownload(
            "/api/accounts-payable/purchase-orders/{$po->id}/export.docx", $token, "{$po->po_number}.docx",
        );
    }

    public function test_purchase_order_email_names_the_supplier_when_it_has_no_address(): void
    {
        $company = Company::factory()->create();
        [, $token] = $this->ownerToken($company);
        $supplier = $this->supplier($company, null);
        $po = PurchaseOrder::factory()->for($company)->create(['supplier_id' => $supplier->id]);

        $this->postJson("/api/accounts-payable/purchase-orders/{$po->id}/email", [], $this->headers($token))
            ->assertStatus(422)
            ->assertJson(['detail' => "{$supplier->name} has no email on file -- add one on the Company/Individual page first."]);
    }

    public function test_purchase_order_export_from_another_company_is_not_found(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        [, $tokenA] = $this->ownerToken($companyA);
        $poB = PurchaseOrder::factory()->for($companyB)->create(['supplier_id' => $this->supplier($companyB)->id]);

        $this->getJson("/api/accounts-payable/purchase-orders/{$poB->id}/export.docx", $this->headers($tokenA))->assertStatus(404);
    }

    // ---- Payment Voucher / Receipt Voucher ----------------------------

    public function test_payment_voucher_word_export_and_email_guard(): void
    {
        $company = Company::factory()->create();
        [, $token] = $this->ownerToken($company);
        $supplier = $this->supplier($company, null);
        $payment = SupplierPayment::factory()->for($company)->create(['supplier_id' => $supplier->id]);

        $this->assertDocxDownload(
            "/api/accounts-payable/payments/{$payment->id}/export.docx", $token, "{$payment->voucher_number}.docx",
        );
        $this->postJson("/api/accounts-payable/payments/{$payment->id}/email", [], $this->headers($token))->assertStatus(422);
    }

    public function test_receipt_word_export_and_email_guard(): void
    {
        $company = Company::factory()->create();
        [, $token] = $this->ownerToken($company);
        $payment = Payment::factory()->for($company)->create([
            'customer_id' => $this->customer($company, null)->id,
        ]);

        $this->assertDocxDownload(
            "/api/accounts-receivable/payments/{$payment->id}/export.docx", $token, "{$payment->voucher_number}.docx",
        );
        $this->postJson("/api/accounts-receivable/payments/{$payment->id}/email", [], $this->headers($token))->assertStatus(422);
    }

    // ---- Statement of Accounts (AR-001) -------------------------------

    public function test_customer_statement_json_lists_only_unpaid_invoices(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        $customer = $this->customer($company);
        $outstanding = $this->invoice($company, $owner, $customer);
        $paid = $this->invoice($company, $owner, $customer);
        $paid->update(['status' => Invoice::STATUS_PAID, 'amount_paid_sgd' => $paid->total_amount_sgd]);

        $response = $this->getJson("/api/accounts-receivable/statement/{$customer->id}", $this->headers($token));

        $response->assertOk();
        $this->assertSame($customer->name, $response->json('customer_name'));
        $this->assertSame(now()->toDateString(), $response->json('as_at'));
        $this->assertCount(1, $response->json('lines'));
        $this->assertSame($outstanding->invoice_number, $response->json('lines.0.invoice_number'));
        $this->assertEqualsWithDelta(
            (float) $outstanding->total_amount_sgd, $response->json('total_outstanding_sgd'), 0.01,
        );
    }

    public function test_customer_statement_honours_an_as_at_date(): void
    {
        $company = Company::factory()->create();
        [, $token] = $this->ownerToken($company);
        $customer = $this->customer($company);

        $response = $this->getJson(
            "/api/accounts-receivable/statement/{$customer->id}?as_at=2026-01-31", $this->headers($token),
        );

        $response->assertOk()->assertJson(['as_at' => '2026-01-31']);
    }

    public function test_customer_statement_word_export(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        $customer = $this->customer($company);
        $this->invoice($company, $owner, $customer);

        $this->assertDocxDownload(
            "/api/accounts-receivable/statement/{$customer->id}/export.docx",
            $token,
            'Statement-'.$customer->name.'-'.now()->toDateString().'.docx',
        );
    }

    public function test_customer_statement_email_without_an_address_is_422(): void
    {
        $company = Company::factory()->create();
        [, $token] = $this->ownerToken($company);
        $customer = $this->customer($company, null);

        $this->postJson("/api/accounts-receivable/statement/{$customer->id}/email", [], $this->headers($token))
            ->assertStatus(422);
    }

    public function test_another_companys_customer_has_no_statement(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        [, $tokenA] = $this->ownerToken($companyA);
        $customerB = $this->customer($companyB);

        $this->getJson("/api/accounts-receivable/statement/{$customerB->id}", $this->headers($tokenA))->assertStatus(404);
        $this->getJson("/api/accounts-receivable/statement/{$customerB->id}/export.docx", $this->headers($tokenA))->assertStatus(404);
        $this->postJson("/api/accounts-receivable/statement/{$customerB->id}/email", [], $this->headers($tokenA))->assertStatus(404);
    }

    public function test_statement_needs_the_accounts_receivable_module(): void
    {
        $company = Company::factory()->create();
        $customer = $this->customer($company);
        ModuleCatalog::firstOrCreate(['key' => 'accounts_receivable'], ['name' => 'Accounts Receivable', 'is_built' => true]);
        $token = $this->staffToken($company, 'accounts_receivable', GroupModuleAuthority::FULL, enabled: false);

        $this->getJson("/api/accounts-receivable/statement/{$customer->id}", $this->headers($token))->assertStatus(403);
    }

    public function test_a_user_with_no_group_cannot_export_anything(): void
    {
        $company = Company::factory()->create();
        [$owner] = $this->ownerToken($company);
        $invoice = $this->invoice($company, $owner, $this->customer($company));
        $staff = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        $login = $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234']);

        $this->getJson("/api/invoices/{$invoice->id}/export.docx", $this->headers($login->json('access_token')))
            ->assertStatus(403);
    }

    // ---- helpers -------------------------------------------------------

    private function staffToken(Company $company, string $moduleKey, string $level, bool $enabled = true): string
    {
        if ($enabled) {
            CompanyModule::firstOrCreate(
                ['company_id' => $company->id, 'module_key' => $moduleKey], ['enabled' => true],
            );
        }
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create([
            'group_id' => $group->id, 'module_key' => $moduleKey, 'access_level' => $level,
        ]);
        $staff = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        UserCompanyAccess::create(['user_id' => $staff->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $login = $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234']);

        return $login->json('access_token');
    }

    private function requireLibreOffice(): void
    {
        exec('command -v soffice 2>/dev/null', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('LibreOffice (soffice) is not installed -- the PDF attachment step cannot run.');
        }
    }
}
