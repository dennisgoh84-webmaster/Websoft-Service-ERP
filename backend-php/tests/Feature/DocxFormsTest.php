<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Invoice;
use App\Models\JobOrder;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\ServiceRecord;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\AccountsReceivableService;
use App\Services\BillingService;
use App\Services\ContractService;
use App\Services\DocxForms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Content-level coverage of App\Services\DocxForms -- every one of the
 * seven Word forms backend/app/services/docx_forms.py generates is
 * actually built here, the .docx is unzipped, and its real
 * WordprocessingML is asserted on. Generating bytes is not enough: the
 * point of these tests is that the *rendered document* matches the
 * Python original section for section (see that service's PARITY
 * NOTES), so each test checks the title, the letterhead block, the
 * table headers, and the exact money/date strings.
 */
class DocxFormsTest extends TestCase
{
    use RefreshDatabase;

    /** All visible text of the .docx, in document order, space-separated. */
    private function docxText(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx-test-');
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'Generated .docx is not a readable zip archive');
        $xml = $zip->getFromName('word/document.xml');
        $this->assertNotFalse($xml, 'Generated .docx has no word/document.xml');
        $zip->close();
        @unlink($path);

        // `<w:t...>` only -- NOT `<w:tbl>`/`<w:tblPr>`/`<w:tr>`, which
        // share the prefix.
        preg_match_all('/<w:t(?:\s[^>]*)?>(.*?)<\/w:t>/s', $xml, $matches);

        return html_entity_decode(implode(' ', $matches[1]), ENT_QUOTES | ENT_XML1);
    }

    /** The raw word/document.xml, for structural assertions (tables, breaks). */
    private function docxXml(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx-test-');
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($path);

        return $xml;
    }

    private function company(): Company
    {
        return Company::factory()->create([
            'name' => 'Webmaster Consultancy Pte Ltd',
            'address' => '1 Raffles Place, Singapore 048616',
            'phone' => '+65 6123 4567',
            'website' => 'https://webmaster.example',
            'uen' => '201812345A',
            'gst_registration_no' => 'M2-1234567-8',
        ]);
    }

    private function customer(Company $company): CompanyIndividual
    {
        return CompanyIndividual::factory()->for($company)->create([
            'name' => 'Acme Logistics Pte Ltd',
            'uen' => '199912345B',
            'contact_person' => 'Jane Tan',
            'billing_email' => 'ap@acme.example',
            'phone' => '+65 6999 1111',
            'address_line1' => '10 Anson Road',
            'address_line2' => '#20-01',
            'address_city' => 'Singapore',
            'address_country' => 'Singapore',
            'payment_terms_days' => 30,
        ]);
    }

    public function test_invoice_docx_carries_the_letterhead_totals_and_gst(): void
    {
        $company = $this->company();
        // Confirmed 2026-09-10: GST-registered, standard-rated, 9%.
        TaxCode::create(['company_id' => $company->id, 'code' => 'SR', 'name' => 'Standard-rated', 'rate_percent' => 9, 'is_active' => true]);
        $owner = User::factory()->for($company)->create(['role' => User::ROLE_OWNER]);
        $customer = $this->customer($company);
        $contract = ContractService::createContract(
            companyId: $company->id, customerId: $customer->id, contractedHours: 10,
            contractValueSgd: 3000, startDate: now()->toDateString(), actorUserId: $owner->id,
        );
        $invoice = BillingService::issueContractAnnualInvoice($contract, $owner->id);

        $text = $this->docxText(DocxForms::invoiceToDocx($invoice, $customer, $company));

        $this->assertStringContainsString('TAX INVOICE', $text);
        $this->assertStringContainsString('Webmaster Consultancy Pte Ltd', $text);
        $this->assertStringContainsString('Tel: +65 6123 4567', $text);
        $this->assertStringContainsString('Business Reg# 201812345A', $text);
        $this->assertStringContainsString('GST Reg# M2-1234567-8', $text);
        $this->assertStringContainsString($invoice->invoice_number, $text);
        $this->assertStringContainsString('Issued: '.$invoice->issued_at->format('Y-m-d'), $text);
        $this->assertStringContainsString('Due: '.$invoice->due_date->toDateString(), $text);
        // Bill To block.
        $this->assertStringContainsString('Bill To', $text);
        $this->assertStringContainsString('Acme Logistics Pte Ltd', $text);
        $this->assertStringContainsString('UEN: 199912345B', $text);
        $this->assertStringContainsString('Contact Person: Jane Tan', $text);
        $this->assertStringContainsString('Contact Email: ap@acme.example', $text);
        $this->assertStringContainsString('10 Anson Road, #20-01, Singapore, Singapore', $text);
        // Line table + totals: SGD 3,000 net, 9% GST, 3,270 gross.
        $this->assertStringContainsString('Description', $text);
        $this->assertStringContainsString('Unit Price ($)', $text);
        $this->assertStringContainsString('3000.00', $text);
        $this->assertStringContainsString('Subtotal', $text);
        $this->assertStringContainsString('Tax 9.00% (SR)', $text);
        $this->assertStringContainsString('270.00', $text);
        $this->assertStringContainsString('Grand Total (SGD)', $text);
        $this->assertStringContainsString('3270.00', $text);
    }

    public function test_invoice_docx_omits_the_due_date_line_when_there_is_none(): void
    {
        $company = $this->company();
        $customer = CompanyIndividual::factory()->for($company)->create(['payment_terms_days' => null]);
        $invoice = Invoice::create([
            'id' => (string) Str::orderedUuid(), 'company_id' => $company->id,
            'customer_id' => $customer->id, 'invoice_number' => 'INV-2026-9999',
            'invoice_type' => Invoice::TYPE_CONTRACT_ANNUAL, 'description' => 'No terms agreed',
            'amount_sgd' => '100.00', 'tax_code' => 'SR', 'gst_rate' => '9.00',
            'gst_amount_sgd' => '9.00', 'total_amount_sgd' => '109.00', 'due_date' => null,
        ]);

        $text = $this->docxText(DocxForms::invoiceToDocx($invoice, $customer, $company));

        $this->assertStringContainsString('INV-2026-9999', $text);
        $this->assertStringNotContainsString('Due:', $text);
    }

    public function test_quotation_docx_lists_every_line_and_the_notes_block(): void
    {
        $company = $this->company();
        $customer = $this->customer($company);
        $quotation = Quotation::factory()->for($company)->create([
            'customer_id' => $customer->id,
            'quotation_number' => 'QUO-2026-0001',
            'quotation_date' => '2026-09-01',
            'valid_until' => '2026-09-30',
            'notes' => 'Prices valid for 30 days.',
            'amount_sgd' => '3000.00', 'tax_code' => 'SR', 'gst_rate' => '9.00',
            'gst_amount_sgd' => '270.00', 'total_amount_sgd' => '3270.00',
        ]);
        QuotationLine::create([
            'id' => (string) Str::orderedUuid(), 'quotation_id' => $quotation->id,
            'description' => 'Managed IT support', 'unit_of_measure' => 'Hours',
            'quantity' => '10.00', 'unit_price_sgd' => '300.00', 'line_total_sgd' => '3000.00',
        ]);

        $text = $this->docxText(DocxForms::quotationToDocx($quotation->fresh('lines'), $customer, $company));

        $this->assertStringContainsString('QUOTATION', $text);
        $this->assertStringContainsString('QUO-2026-0001', $text);
        $this->assertStringContainsString('Date: 2026-09-01', $text);
        $this->assertStringContainsString('Valid Until: 2026-09-30', $text);
        $this->assertStringContainsString('UoM', $text);
        $this->assertStringContainsString('Managed IT support', $text);
        $this->assertStringContainsString('Hours', $text);
        $this->assertStringContainsString('10.00', $text);
        $this->assertStringContainsString('300.00', $text);
        // PRESERVED QUIRK: the Quotation form interpolates float(gst_rate)
        // -> "9.0", where the Invoice form interpolates the Decimal
        // -> "9.00". Both match backend/app/services/docx_forms.py.
        $this->assertStringContainsString('Tax 9.0% (SR)', $text);
        $this->assertStringContainsString('Grand Total (SGD)', $text);
        $this->assertStringContainsString('3270.00', $text);
        $this->assertStringContainsString('Notes:', $text);
        $this->assertStringContainsString('Prices valid for 30 days.', $text);
    }

    public function test_receipt_docx_shows_the_applied_invoices_and_the_unallocated_balance(): void
    {
        $company = $this->company();
        $customer = $this->customer($company);
        $invoice = Invoice::create([
            'id' => (string) Str::orderedUuid(), 'company_id' => $company->id,
            'customer_id' => $customer->id, 'invoice_number' => 'INV-2026-0007',
            'invoice_type' => Invoice::TYPE_CONTRACT_ANNUAL, 'description' => 'Annual support',
            'amount_sgd' => '100.00', 'tax_code' => 'SR', 'gst_rate' => '9.00',
            'gst_amount_sgd' => '9.00', 'total_amount_sgd' => '109.00',
        ]);
        $payment = Payment::factory()->for($company)->create([
            'customer_id' => $customer->id, 'voucher_number' => 'RV-2026-0001',
            'payment_date' => '2026-09-10', 'amount_sgd' => '200.00',
            'method' => Payment::METHOD_BANK_TRANSFER, 'reference' => 'DBS-99182',
        ]);
        PaymentAllocation::create([
            'id' => (string) Str::orderedUuid(), 'company_id' => $company->id,
            'payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount_sgd' => '109.00',
        ]);

        $text = $this->docxText(DocxForms::receiptToDocx(
            $payment->fresh('allocations'), $customer, $company,
            [$invoice->id => $invoice->invoice_number],
        ));

        $this->assertStringContainsString('OFFICIAL RECEIPT', $text);
        $this->assertStringContainsString('RV-2026-0001', $text);
        $this->assertStringContainsString('Date: 2026-09-10', $text);
        $this->assertStringContainsString('Method: bank_transfer', $text);
        $this->assertStringContainsString('Reference: DBS-99182', $text);
        $this->assertStringContainsString('Received From', $text);
        $this->assertStringContainsString('Amount Received: SGD 200.00', $text);
        $this->assertStringContainsString('Applied To', $text);
        $this->assertStringContainsString('INV-2026-0007', $text);
        $this->assertStringContainsString('109.00', $text);
        $this->assertStringContainsString('Unallocated (on account): SGD 91.00', $text);
    }

    public function test_receipt_docx_has_no_applied_to_table_when_nothing_is_allocated(): void
    {
        $company = $this->company();
        $customer = $this->customer($company);
        $payment = Payment::factory()->for($company)->create([
            'customer_id' => $customer->id, 'amount_sgd' => '50.00',
        ]);

        $bytes = DocxForms::receiptToDocx($payment->fresh('allocations'), $customer, $company, []);

        $this->assertStringNotContainsString('Applied To', $this->docxText($bytes));
        $this->assertStringNotContainsString('<w:tbl>', $this->docxXml($bytes));
    }

    public function test_purchase_order_docx_carries_the_status_totals_and_signature_block(): void
    {
        $company = $this->company();
        $supplier = CompanyIndividual::factory()->for($company)->create([
            'name' => 'Hardware Supplies Pte Ltd', 'is_supplier' => true,
            'gst_registration_no' => 'M9-7654321-0', 'billing_email' => 'sales@hw.example',
            'phone' => '+65 6222 3333', 'address_line1' => '5 Changi Road', 'address_city' => 'Singapore',
        ]);
        $po = PurchaseOrder::factory()->for($company)->create([
            'supplier_id' => $supplier->id, 'po_number' => 'PO-2026-0001',
            'order_date' => '2026-09-05', 'description' => '10 x Laptop',
            'amount_sgd' => '1000.00', 'gst_amount_sgd' => '90.00', 'total_amount_sgd' => '1090.00',
            'status' => PurchaseOrder::STATUS_PENDING_APPROVAL,
        ]);

        $text = $this->docxText(DocxForms::purchaseOrderToDocx($po, $supplier, $company));

        $this->assertStringContainsString('PURCHASE ORDER', $text);
        $this->assertStringContainsString('PO-2026-0001', $text);
        $this->assertStringContainsString('Date: 2026-09-05', $text);
        // Python: status.value.replace('_', ' ').title().
        $this->assertStringContainsString('Status: Pending Approval', $text);
        $this->assertStringContainsString('Supplier', $text);
        $this->assertStringContainsString('Hardware Supplies Pte Ltd', $text);
        $this->assertStringContainsString('GST Reg# M9-7654321-0', $text);
        $this->assertStringContainsString('Email: sales@hw.example', $text);
        $this->assertStringContainsString('10 x Laptop', $text);
        $this->assertStringContainsString('1000.00', $text);
        $this->assertStringContainsString('GST', $text);
        $this->assertStringContainsString('90.00', $text);
        $this->assertStringContainsString('Grand Total (SGD)', $text);
        $this->assertStringContainsString('1090.00', $text);
        $this->assertStringContainsString('quote the PO number', $text);
        $this->assertStringContainsString('Authorised by:', $text);
    }

    public function test_payment_voucher_docx_shows_the_applied_bills(): void
    {
        $company = $this->company();
        $supplier = CompanyIndividual::factory()->for($company)->create([
            'name' => 'Hardware Supplies Pte Ltd', 'is_supplier' => true,
            'gst_registration_no' => 'M9-7654321-0',
        ]);
        $bill = SupplierInvoice::factory()->for($company)->create([
            'supplier_id' => $supplier->id, 'bill_number' => 'BILL-2026-0003',
        ]);
        $payment = SupplierPayment::factory()->for($company)->create([
            'supplier_id' => $supplier->id, 'voucher_number' => 'PV-2026-0001',
            'payment_date' => '2026-09-11', 'amount_sgd' => '500.00',
            'method' => 'cheque', 'reference' => 'CHQ-0042',
        ]);
        SupplierPaymentAllocation::create([
            'id' => (string) Str::orderedUuid(), 'company_id' => $company->id,
            'payment_id' => $payment->id, 'supplier_invoice_id' => $bill->id, 'amount_sgd' => '300.00',
        ]);

        $text = $this->docxText(DocxForms::paymentVoucherToDocx(
            $payment->fresh('allocations'), $supplier, $company, [$bill->id => $bill->bill_number],
        ));

        $this->assertStringContainsString('PAYMENT VOUCHER', $text);
        $this->assertStringContainsString('PV-2026-0001', $text);
        $this->assertStringContainsString('Date: 2026-09-11', $text);
        $this->assertStringContainsString('Method: cheque', $text);
        $this->assertStringContainsString('Reference: CHQ-0042', $text);
        $this->assertStringContainsString('Paid To', $text);
        $this->assertStringContainsString('Amount Paid: SGD 500.00', $text);
        $this->assertStringContainsString('Applied To', $text);
        $this->assertStringContainsString('Bill', $text);
        $this->assertStringContainsString('BILL-2026-0003', $text);
        $this->assertStringContainsString('300.00', $text);
        $this->assertStringContainsString('Unallocated: SGD 200.00', $text);
    }

    public function test_service_record_docx_shows_the_rounding_and_completion_rows(): void
    {
        $company = $this->company();
        $customer = $this->customer($company);
        $jobOrder = JobOrder::factory()->for($company)->create([
            'customer_id' => $customer->id, 'job_order_number' => 'JO-2026-0001',
            'subject' => 'Server not booting',
        ]);
        $record = ServiceRecord::factory()->for($company)->create([
            'job_order_id' => $jobOrder->id, 'service_record_number' => 'SR-2026-0001',
            'work_date' => '2026-09-12', 'raw_minutes' => 23, 'rounded_minutes' => 30,
            'status' => ServiceRecord::STATUS_APPROVED,
            'completion_status' => ServiceRecord::COMPLETED,
            'is_after_hours' => true, 'deducted_minutes' => 60,
        ]);

        $text = $this->docxText(DocxForms::serviceRecordToDocx($record, $jobOrder, $customer, $company));

        $this->assertStringContainsString('SERVICE RECORD', $text);
        $this->assertStringContainsString('SR-2026-0001', $text);
        $this->assertStringContainsString('Job Order: JO-2026-0001 -- Server not booting', $text);
        $this->assertStringContainsString('Work Date: 2026-09-12', $text);
        $this->assertStringContainsString('Status: Approved', $text);
        $this->assertStringContainsString('Company / Individual', $text);
        $this->assertStringContainsString('Acme Logistics Pte Ltd', $text);
        $this->assertStringContainsString('Time logged (raw)', $text);
        $this->assertStringContainsString('23 min', $text);
        $this->assertStringContainsString('Time logged (rounded, SRV-007)', $text);
        $this->assertStringContainsString('30 min', $text);
        $this->assertStringContainsString('Completed', $text);
        $this->assertStringContainsString('After hours / weekend / holiday', $text);
        $this->assertStringContainsString('Yes', $text);
        $this->assertStringContainsString('Minutes deducted from contract', $text);
        $this->assertStringContainsString('60 min', $text);
        $this->assertStringContainsString('Signature & Company Stamp:', $text);
    }

    public function test_service_record_docx_uses_the_not_yet_completed_wording_and_drops_the_deduction_row(): void
    {
        $company = $this->company();
        $customer = $this->customer($company);
        $jobOrder = JobOrder::factory()->for($company)->create(['customer_id' => $customer->id]);
        $record = ServiceRecord::factory()->for($company)->create([
            'job_order_id' => $jobOrder->id,
            'completion_status' => ServiceRecord::UNCOMPLETED,
            'is_after_hours' => false, 'deducted_minutes' => null,
        ]);

        $text = $this->docxText(DocxForms::serviceRecordToDocx($record, $jobOrder, $customer, $company));

        $this->assertStringContainsString('Not yet completed -- another visit expected', $text);
        $this->assertStringNotContainsString('Minutes deducted from contract', $text);
    }

    public function test_statement_docx_lists_outstanding_invoices_with_the_disputed_marker(): void
    {
        $company = $this->company();
        $customer = $this->customer($company);
        $owner = User::factory()->for($company)->create(['role' => User::ROLE_OWNER]);
        $contract = ContractService::createContract(
            companyId: $company->id, customerId: $customer->id, contractedHours: 10,
            contractValueSgd: 1000, startDate: now()->toDateString(), actorUserId: $owner->id,
        );
        $invoice = BillingService::issueContractAnnualInvoice($contract, $owner->id);
        $invoice->update(['is_disputed' => true]);
        Payment::factory()->for($company)->create([
            'customer_id' => $customer->id, 'amount_sgd' => '25.00',
        ]);

        $statement = AccountsReceivableService::buildCustomerStatement($customer, $company->id);
        $text = $this->docxText(DocxForms::statementToDocx($statement, $customer, $company));

        $this->assertStringContainsString('STATEMENT OF ACCOUNTS', $text);
        $this->assertStringContainsString('Acme Logistics Pte Ltd', $text);
        $this->assertStringContainsString('As at: '.now()->toDateString(), $text);
        $this->assertStringContainsString('Payment terms: Net 30 days', $text);
        $this->assertStringContainsString('Outstanding ($)', $text);
        $this->assertStringContainsString($invoice->invoice_number.' (disputed)', $text);
        // Whatever the company's own tax configuration produces -- the
        // statement must show the invoice's real total, not a figure
        // recomputed by the form.
        $total = number_format((float) $invoice->total_amount_sgd, 2, '.', '');
        $this->assertStringContainsString($total, $text);
        $this->assertStringContainsString("Total Outstanding: SGD {$total}", $text);
        $this->assertStringContainsString('Unallocated credit on account: SGD 25.00', $text);
    }

    public function test_statement_docx_renders_a_dash_for_an_invoice_with_no_due_date(): void
    {
        $company = $this->company();
        $customer = CompanyIndividual::factory()->for($company)->create(['payment_terms_days' => null]);
        Invoice::create([
            'id' => (string) Str::orderedUuid(), 'company_id' => $company->id,
            'customer_id' => $customer->id, 'invoice_number' => 'INV-2026-0100',
            'invoice_type' => Invoice::TYPE_CONTRACT_ANNUAL, 'description' => 'No terms',
            'amount_sgd' => '100.00', 'tax_code' => 'SR', 'gst_rate' => '9.00',
            'gst_amount_sgd' => '9.00', 'total_amount_sgd' => '109.00', 'due_date' => null,
        ]);

        $statement = AccountsReceivableService::buildCustomerStatement($customer, $company->id);
        $text = $this->docxText(DocxForms::statementToDocx($statement, $customer, $company));

        $this->assertStringContainsString('INV-2026-0100', $text);
        $this->assertStringContainsString(' - ', ' '.$text.' ');
        // No payment terms on file -> the "Payment terms: Net N days"
        // line is omitted entirely, never invented.
        $this->assertStringNotContainsString('Payment terms:', $text);
        $this->assertSame(0, $statement['lines'][0]['days_overdue']);
    }

    public function test_ampersands_in_document_text_are_escaped(): void
    {
        // Regression (found by the Playwright verification pass, not by
        // an earlier test): PHPWord writes `<w:t>` content verbatim
        // unless output escaping is switched on, so a literal "&" --
        // which the Service Record form emits on EVERY document
        // ("Signature & Company Stamp"), and which any customer named
        // "Smith & Sons" would add -- produced malformed XML.
        // LibreOffice then refused the file with "source file could not
        // be loaded", breaking the PDF attachment behind every Email
        // button while the Word download still appeared to work.
        $company = $this->company();
        $customer = CompanyIndividual::factory()->for($company)->create(['name' => 'Smith & Sons <Holdings>']);
        $jobOrder = JobOrder::factory()->for($company)->create([
            'customer_id' => $customer->id, 'subject' => 'Backup & restore',
        ]);
        $record = ServiceRecord::factory()->for($company)->create(['job_order_id' => $jobOrder->id]);

        $bytes = DocxForms::serviceRecordToDocx($record, $jobOrder, $customer, $company);
        $xml = $this->docxXml($bytes);

        $this->assertStringContainsString('Signature &amp; Company Stamp:', $xml);
        $this->assertStringContainsString('Smith &amp; Sons &lt;Holdings&gt;', $xml);
        $this->assertStringContainsString('Backup &amp; restore', $xml);
        // And the package still parses as XML, which is what
        // LibreOffice was rejecting.
        $this->assertInstanceOf(\SimpleXMLElement::class, simplexml_load_string($xml));
        // The decoded text is unchanged -- escaping is markup-level only.
        $this->assertStringContainsString('Signature & Company Stamp:', $this->docxText($bytes));
    }

    public function test_every_form_is_a_valid_docx_package(): void
    {
        $company = $this->company();
        $customer = $this->customer($company);
        $quotation = Quotation::factory()->for($company)->create(['customer_id' => $customer->id]);

        $bytes = DocxForms::quotationToDocx($quotation->fresh('lines'), $customer, $company);

        $path = tempnam(sys_get_temp_dir(), 'docx-pkg-');
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        foreach (['[Content_Types].xml', '_rels/.rels', 'word/document.xml', 'word/styles.xml'] as $part) {
            $this->assertNotFalse($zip->getFromName($part), "Missing OOXML part: {$part}");
        }
        // The table style the Python forms reference must really be
        // defined in this package, not a dangling reference -- see
        // DocxForms' PARITY NOTES.
        $this->assertStringContainsString('Light Grid Accent 1', (string) $zip->getFromName('word/styles.xml'));
        $zip->close();
        @unlink($path);
    }
}
