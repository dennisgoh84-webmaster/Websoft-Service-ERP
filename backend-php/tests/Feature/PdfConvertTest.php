<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\JobOrder;
use App\Models\PurchaseOrder;
use App\Models\ServiceRecord;
use App\Services\DocumentEmail;
use App\Services\DocumentEmailError;
use App\Services\DocxForms;
use App\Services\PdfConvert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * App\Services\PdfConvert + App\Services\DocumentEmail, converted from
 * backend/app/services/pdf_convert.py and document_email.py.
 *
 * The PDF round-trip is a real LibreOffice-headless conversion of the
 * exact .docx bytes the "Word" export button serves -- that shared
 * source is the whole point of the design (one template, two output
 * formats, so the Word file and the emailed PDF can never drift), so
 * the test exercises it rather than mocking it.
 *
 * SYSTEM DEPENDENCY: this needs LibreOffice *Writer* installed, not
 * just `libreoffice-core` -- core alone has no Writer import/export
 * filter and every conversion fails with "source file could not be
 * loaded". See DEV_SETUP.md. When soffice is absent the round-trip
 * tests skip loudly rather than passing vacuously.
 */
class PdfConvertTest extends TestCase
{
    use RefreshDatabase;

    private function requireLibreOffice(): void
    {
        exec('command -v soffice 2>/dev/null', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('LibreOffice (soffice) is not installed -- PDF conversion cannot be exercised here.');
        }
    }

    private function purchaseOrderDocx(): string
    {
        $company = Company::factory()->create(['name' => 'Webmaster Consultancy Pte Ltd']);
        $supplier = CompanyIndividual::factory()->for($company)->create([
            'name' => 'Hardware Supplies Pte Ltd', 'is_supplier' => true,
        ]);
        $po = PurchaseOrder::factory()->for($company)->create([
            'supplier_id' => $supplier->id, 'po_number' => 'PO-2026-0001',
        ]);

        return DocxForms::purchaseOrderToDocx($po, $supplier, $company);
    }

    public function test_a_generated_docx_really_converts_to_a_pdf(): void
    {
        $this->requireLibreOffice();

        $pdf = PdfConvert::docxBytesToPdf($this->purchaseOrderDocx(), 120);

        $this->assertStringStartsWith('%PDF-', $pdf, 'LibreOffice did not return a PDF');
        $this->assertStringContainsString('%%EOF', substr($pdf, -1024), 'PDF has no trailer');
        // A one-page form with a letterhead, a table and a signature
        // line is never a few hundred bytes; this catches an empty or
        // truncated conversion that still starts with the magic bytes.
        $this->assertGreaterThan(2000, strlen($pdf));
    }

    public function test_conversion_leaves_no_temporary_files_behind(): void
    {
        $this->requireLibreOffice();

        $before = glob(sys_get_temp_dir().'/websoft-pdf-*');
        PdfConvert::docxBytesToPdf($this->purchaseOrderDocx(), 120);
        $after = glob(sys_get_temp_dir().'/websoft-pdf-*');

        $this->assertSame($before, $after);
    }

    public function test_a_service_record_with_an_ampersand_still_converts(): void
    {
        $this->requireLibreOffice();

        // The exact regression the Playwright pass caught: every
        // Service Record carries "Signature & Company Stamp", and an
        // unescaped "&" made LibreOffice reject the file with "source
        // file could not be loaded" -- so the Word download looked
        // fine while the Email button failed. See
        // DocxForms::newDocument()'s output-escaping note.
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create(['name' => 'Smith & Sons']);
        $jobOrder = JobOrder::factory()->for($company)->create([
            'customer_id' => $customer->id, 'subject' => 'Backup & restore',
        ]);
        $record = ServiceRecord::factory()->for($company)->create(['job_order_id' => $jobOrder->id]);

        $pdf = PdfConvert::docxBytesToPdf(
            DocxForms::serviceRecordToDocx($record, $jobOrder, $customer, $company), 120,
        );

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(2000, strlen($pdf));
    }

    public function test_an_unconfigured_mailer_is_a_422_not_a_crash(): void
    {
        $this->requireLibreOffice();

        // Python's own fail-open contract: with no SMTP account set up,
        // send_document_email raises DocumentEmailError(status_code=422)
        // so the API returns a clear message instead of pretending to
        // have sent anything. There is no SMTP server in the test
        // environment, so this is the send path that can be exercised
        // end to end -- the PDF really is built and attached first.
        config(['websoft.smtp_host' => null, 'websoft.smtp_from_email' => null]);

        try {
            DocumentEmail::sendDocumentEmail(
                'ap@acme.example', 'Purchase Order PO-2026-0001', 'Body', $this->purchaseOrderDocx(), 'PO-2026-0001',
            );
            $this->fail('Expected a DocumentEmailError');
        } catch (DocumentEmailError $e) {
            $this->assertSame(422, $e->statusCode);
            $this->assertStringContainsString('Email sending is not configured yet', $e->getMessage());
        }
    }
}

/*
 * NOT covered here, deliberately: a *failed* soffice run mapping to
 * PdfConversionError. Passing non-.docx bytes does not produce one --
 * LibreOffice falls back to importing unknown content as plain text
 * and still emits a PDF, and `backend/`'s pdf_convert.py behaves
 * identically because it shells out to the same binary with the same
 * arguments. The genuine failure modes (soffice absent, soffice
 * timing out) cannot be induced from inside the test process without
 * mocking the binary itself, which would test the mock rather than
 * the conversion. See PdfConvert::looksLikeMissingBinary() for the
 * not-installed branch.
 */
