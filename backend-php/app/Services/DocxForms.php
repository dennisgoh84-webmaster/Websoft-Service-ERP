<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\JobOrder;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\ServiceRecord;
use App\Models\SupplierPayment;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Word (.docx) versions of the printable forms. Direct conversion of
 * backend/app/services/docx_forms.py -- same content as the matching
 * print page (e.g. frontend/src/pages/InvoicePrintPage.tsx for
 * invoiceToDocx), built with PHPWord so it opens as a normal, editable
 * Word document. Each form gets its own small function here rather
 * than a generic templating layer, since a one-record form's layout is
 * specific to what it's showing.
 *
 * DEPENDENCY (recorded per CLAUDE.md's "do not introduce unnecessary
 * dependencies" rule, the same way brick/math was): PHP has no
 * built-in DOCX writer, and `phpoffice/phpword` is the direct
 * counterpart to the Python backend's `python-docx`. The only
 * alternative is hand-assembling OOXML (WordprocessingML parts, the
 * content-types/relationship parts and the zip container) by hand,
 * which is strictly worse -- more code, no styling primitives, and a
 * format that has to be kept valid by hand. See
 * docs/php-conversion-plan.md.
 *
 * PARITY NOTES (python-docx vs. PHPWord -- byte-identical output is
 * impossible; the *rendered* document is what is matched):
 *
 * - python-docx's `table.style = "Light Grid Accent 1"` references a
 *   built-in Word table style that ships inside python-docx's own
 *   default template. PHPWord's default template carries no such
 *   style, so a bare string reference here would dangle and the table
 *   would render borderless. Instead the same style *name* is
 *   registered on each document (see `registerGridTableStyle()`) with
 *   an equivalent look -- a full single-line grid in Word's Accent 1
 *   blue with a shaded header row. PHPWord's table style cannot carry
 *   the header row's bold run formatting (only its shading), so each
 *   form bolds its own header cells explicitly; the rendered result
 *   matches, the underlying markup does not.
 * - python-docx turns a "\n" inside a run into a `<w:br/>`; PHPWord
 *   writes text verbatim, so `addRun()` below splits on "\n" and emits
 *   a real text break, matching the rendered line breaks.
 * - Everything else (section order, field labels, table columns,
 *   number/date formatting, totals and GST treatment, the letterhead
 *   block) is a literal transcription of the Python functions,
 *   including their quirks -- see `pyFloat()`.
 */
class DocxForms
{
    /**
     * The table style name the Python forms use. Kept as the literal
     * python-docx style name so the two sources stay diffable.
     */
    private const GRID_STYLE = 'Light Grid Accent 1';

    /**
     * Python's `f"{x:.2f}"` -- fixed 2dp, no thousands separator.
     * Accepts the string an Eloquent `decimal:2` cast hands back as
     * readily as a float.
     */
    private static function money(string|float|int|null $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    /**
     * Python's `str(float(x))` / f-string interpolation of a bare
     * float -- `9.0` renders as "9.0", not PHP's own "9". Used only
     * where the Python source interpolates a float without a format
     * spec (the Quotation totals' "Tax 9.0% (SR)" label). PHP's
     * `var_export()` is the closest equivalent: it keeps the ".0" on a
     * whole float, exactly like Python's repr.
     *
     * PRESERVED QUIRK: the Invoice form interpolates the *Decimal*
     * (`{invoice.gst_rate}` -> "9.00") while the Quotation form
     * interpolates `float(...)` (-> "9.0"), so the same rate is
     * labelled differently on the two documents. That is what
     * backend/ does today; it is reproduced rather than tidied up.
     */
    private static function pyFloat(string|float|int|null $value): string
    {
        return var_export((float) ($value ?? 0), true);
    }

    /**
     * DD/MM/YYYY on every generated document (Dennis, 2026-09-24: "all
     * date/time format to follow DD/MM/YYYY") -- was Python's
     * `date.isoformat()`. Null-safe like the callers' own guards, and
     * accepts the bare YYYY-MM-DD strings the statement lines carry.
     */
    private static function docDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('d/m/Y');
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $value, $m)) {
            return "{$m[3]}/{$m[2]}/{$m[1]}";
        }

        return (string) $value;
    }

    /**
     * python-docx's `run = paragraph.add_run(text)` -- "\n" inside the
     * text becomes a real line break rather than a literal newline
     * character (which Word renders as a space).
     *
     * @param  array<string, mixed>  $style
     */
    private static function addRun(TextRun $run, string $text, array $style = []): void
    {
        $parts = explode("\n", $text);
        foreach ($parts as $i => $part) {
            if ($part !== '') {
                $run->addText($part, $style);
            }
            if ($i < count($parts) - 1) {
                $run->addTextBreak();
            }
        }
    }

    /** See the PARITY NOTES above -- stands in for Word's built-in table style. */
    private static function registerGridTableStyle(PhpWord $doc): void
    {
        $doc->addTableStyle(self::GRID_STYLE, [
            'borderColor' => '5B9BD5',
            'borderSize' => 6,
            'cellMargin' => 60,
        ], [
            'bgColor' => 'DEEAF6',
            'bold' => true,
        ]);
    }

    /** A new document + its single section, matching python-docx's `Document()`. */
    private static function newDocument(): array
    {
        // PHPWord writes run text into `<w:t>` VERBATIM by default --
        // `outputEscapingEnabled` is false out of the box -- so a
        // literal "&" (e.g. the Service Record's "Signature & Company
        // Stamp" line, or any customer named "Smith & Sons") produces
        // malformed XML: Word repairs it, but LibreOffice refuses the
        // file outright with "source file could not be loaded", which
        // breaks the PDF conversion behind every Email button.
        // python-docx escapes unconditionally, so turning this on is
        // what actually matches backend/'s behaviour. Found by the
        // Playwright pass, not by a unit test -- see
        // DocxFormsTest::test_ampersands_in_document_text_are_escaped.
        Settings::setOutputEscapingEnabled(true);

        $doc = new PhpWord;
        self::registerGridTableStyle($doc);
        $section = $doc->addSection();

        return [$doc, $section];
    }

    /** python-docx's `doc.save(buf); return buf.getvalue()`. */
    private static function toBytes(PhpWord $doc): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'websoft-docx-');
        try {
            IOFactory::createWriter($doc, 'Word2007')->save($tmp);

            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /** The centred, bold, 16pt document title every form opens with. */
    private static function addTitle(Section $section, string $text): void
    {
        $section->addText($text, ['bold' => true, 'size' => 16], ['alignment' => Jc::CENTER]);
    }

    /** `cell.text = "..."` in python-docx. */
    private static function cell(Table $table, int $width, string $text, bool $bold = false): void
    {
        $table->addCell($width)->addText($text, $bold ? ['bold' => true] : []);
    }

    /** The comma-joined customer/supplier address the Python forms build. */
    private static function addressLine(?CompanyIndividual $party): string
    {
        if (! $party) {
            return '';
        }

        return implode(', ', array_filter([
            $party->address_line1,
            $party->address_line2,
            $party->address_city,
            $party->address_country,
        ]));
    }

    public static function invoiceToDocx(Invoice $invoice, ?CompanyIndividual $customer, ?Company $company): string
    {
        [$doc, $section] = self::newDocument();

        $section->addText((string) $company?->name, ['bold' => true]);
        if ($company?->address) {
            $section->addText($company->address);
        }
        if ($company?->phone) {
            $section->addText("Tel: {$company->phone}");
        }
        if ($company?->website) {
            $section->addText($company->website);
        }
        if ($company?->uen) {
            $section->addText("Business Reg# {$company->uen}");
        }
        if ($company?->gst_registration_no) {
            $section->addText("GST Reg# {$company->gst_registration_no}");
        }

        self::addTitle($section, 'TAX INVOICE');

        $meta = $section->addTextRun();
        self::addRun($meta, "{$invoice->invoice_number}\n", ['bold' => true]);
        self::addRun($meta, 'Issued: '.self::docDate($invoice->issued_at)."\n");
        if ($invoice->due_date) {
            self::addRun($meta, 'Due: '.self::docDate($invoice->due_date)."\n");
        }

        $section->addText('Bill To', ['italic' => true]);
        $billTo = $section->addTextRun();
        self::addRun($billTo, ($customer?->name ?? '')."\n", ['bold' => true]);
        if ($customer?->uen) {
            self::addRun($billTo, "UEN: {$customer->uen}\n");
        }
        if ($customer?->contact_person) {
            self::addRun($billTo, "Contact Person: {$customer->contact_person}\n");
        }
        if ($customer?->billing_email) {
            self::addRun($billTo, "Contact Email: {$customer->billing_email}\n");
        }
        if ($customer?->phone) {
            self::addRun($billTo, "Contact No: {$customer->phone}\n");
        }
        $address = self::addressLine($customer);
        if ($address !== '') {
            self::addRun($billTo, $address);
        }

        $table = $section->addTable(self::GRID_STYLE);
        $table->addRow();
        self::cell($table, 4500, 'Description', true);
        self::cell($table, 1500, 'Qty', true);
        self::cell($table, 2000, 'Unit Price ($)', true);
        self::cell($table, 2000, 'Amount ($)', true);
        $table->addRow();
        self::cell($table, 4500, (string) $invoice->description);
        self::cell($table, 1500, '1.00');
        self::cell($table, 2000, self::money($invoice->amount_sgd));
        self::cell($table, 2000, self::money($invoice->amount_sgd));

        $section->addTextBreak();
        self::addTotalsTable($section, [
            ['Subtotal', self::money($invoice->amount_sgd)],
            ["Tax {$invoice->gst_rate}% ({$invoice->tax_code})", self::money($invoice->gst_amount_sgd)],
            ['Grand Total (SGD)', self::money($invoice->total_amount_sgd)],
        ]);

        return self::toBytes($doc);
    }

    /**
     * A credit note (BILL-003), on the same letterhead as the Tax Invoice
     * it credits, naming that invoice and the reason.
     */
    public static function creditNoteToDocx(CreditNote $note, ?CompanyIndividual $customer, ?Company $company): string
    {
        [$doc, $section] = self::newDocument();

        $section->addText((string) $company?->name, ['bold' => true]);
        foreach ([$company?->address, $company?->phone ? "Tel: {$company->phone}" : null, $company?->website,
            $company?->uen ? "Business Reg# {$company->uen}" : null,
            $company?->gst_registration_no ? "GST Reg# {$company->gst_registration_no}" : null] as $line) {
            if ($line) {
                $section->addText($line);
            }
        }

        self::addTitle($section, 'CREDIT NOTE');

        $meta = $section->addTextRun();
        self::addRun($meta, "{$note->credit_note_number}\n", ['bold' => true]);
        self::addRun($meta, 'Issued: '.self::docDate($note->issued_at)."\n");
        self::addRun($meta, 'Against Tax Invoice: '.($note->invoice?->invoice_number ?? '').' dated '.self::docDate($note->invoice?->issued_at)."\n");

        $section->addText('Credit To', ['italic' => true]);
        $to = $section->addTextRun();
        self::addRun($to, ($customer?->name ?? '')."\n", ['bold' => true]);
        if ($customer?->uen) {
            self::addRun($to, "UEN: {$customer->uen}\n");
        }
        $address = self::addressLine($customer);
        if ($address !== '') {
            self::addRun($to, $address);
        }

        $table = $section->addTable(self::GRID_STYLE);
        $table->addRow();
        self::cell($table, 8000, 'Reason', true);
        self::cell($table, 2000, 'Amount ($)', true);
        $table->addRow();
        self::cell($table, 8000, (string) $note->reason);
        self::cell($table, 2000, self::money($note->amount_sgd));

        $section->addTextBreak();
        self::addTotalsTable($section, [
            ['Subtotal', self::money($note->amount_sgd)],
            ["Tax {$note->gst_rate}% ({$note->tax_code})", self::money($note->gst_amount_sgd)],
            ['Total Credit (SGD)', self::money($note->total_amount_sgd)],
        ]);

        return self::toBytes($doc);
    }

    public static function quotationToDocx(Quotation $quotation, ?CompanyIndividual $customer, ?Company $company): string
    {
        [$doc, $section] = self::newDocument();

        $section->addText((string) $company?->name, ['bold' => true]);
        if ($company?->address) {
            $section->addText($company->address);
        }
        if ($company?->phone) {
            $section->addText("Tel: {$company->phone}");
        }
        if ($company?->website) {
            $section->addText($company->website);
        }
        if ($company?->uen) {
            $section->addText("Business Reg# {$company->uen}");
        }
        if ($company?->gst_registration_no) {
            $section->addText("GST Reg# {$company->gst_registration_no}");
        }

        self::addTitle($section, 'QUOTATION');

        $meta = $section->addTextRun();
        self::addRun($meta, "{$quotation->quotation_number}\n", ['bold' => true]);
        self::addRun($meta, 'Date: '.self::docDate($quotation->quotation_date)."\n");
        if ($quotation->valid_until) {
            self::addRun($meta, 'Valid Until: '.self::docDate($quotation->valid_until)."\n");
        }

        $section->addText('To', ['italic' => true]);
        $billTo = $section->addTextRun();
        self::addRun($billTo, ($customer?->name ?? '')."\n", ['bold' => true]);
        if ($customer?->uen) {
            self::addRun($billTo, "UEN: {$customer->uen}\n");
        }
        if ($customer?->contact_person) {
            self::addRun($billTo, "Contact Person: {$customer->contact_person}\n");
        }
        if ($customer?->billing_email) {
            self::addRun($billTo, "Contact Email: {$customer->billing_email}\n");
        }
        if ($customer?->phone) {
            self::addRun($billTo, "Contact No: {$customer->phone}\n");
        }
        $address = self::addressLine($customer);
        if ($address !== '') {
            self::addRun($billTo, $address);
        }

        $table = $section->addTable(self::GRID_STYLE);
        $table->addRow();
        self::cell($table, 3600, 'Description', true);
        self::cell($table, 1200, 'UoM', true);
        self::cell($table, 1200, 'Qty', true);
        self::cell($table, 1800, 'Unit Price ($)', true);
        self::cell($table, 1800, 'Amount ($)', true);
        foreach ($quotation->lines as $line) {
            $table->addRow();
            self::cell($table, 3600, (string) $line->description);
            self::cell($table, 1200, (string) ($line->unit_of_measure ?? ''));
            self::cell($table, 1200, self::money($line->quantity));
            self::cell($table, 1800, self::money($line->unit_price_sgd));
            self::cell($table, 1800, self::money($line->line_total_sgd));
        }

        $section->addTextBreak();
        self::addTotalsTable($section, [
            ['Subtotal', self::money($quotation->amount_sgd)],
            ['Tax '.self::pyFloat($quotation->gst_rate)."% ({$quotation->tax_code})", self::money($quotation->gst_amount_sgd)],
            ['Grand Total (SGD)', self::money($quotation->total_amount_sgd)],
        ]);

        if ($quotation->notes) {
            $section->addTextBreak();
            $notes = $section->addTextRun();
            self::addRun($notes, 'Notes: ', ['bold' => true]);
            self::addRun($notes, (string) $quotation->notes);
        }

        return self::toBytes($doc);
    }

    /**
     * @param  array<string, string>  $invoiceNumbers  invoice id => invoice number
     */
    public static function receiptToDocx(Payment $payment, ?CompanyIndividual $customer, ?Company $company, array $invoiceNumbers): string
    {
        [$doc, $section] = self::newDocument();

        $section->addText((string) $company?->name, ['bold' => true]);
        if ($company?->address) {
            $section->addText($company->address);
        }
        if ($company?->phone) {
            $section->addText("Tel: {$company->phone}");
        }
        if ($company?->uen) {
            $section->addText("Business Reg# {$company->uen}");
        }

        self::addTitle($section, 'OFFICIAL RECEIPT');

        $meta = $section->addTextRun();
        self::addRun($meta, "{$payment->voucher_number}\n", ['bold' => true]);
        self::addRun($meta, 'Date: '.self::docDate($payment->payment_date)."\n");
        self::addRun($meta, "Method: {$payment->method}\n");
        if ($payment->reference) {
            self::addRun($meta, "Reference: {$payment->reference}\n");
        }

        $section->addText('Received From', ['italic' => true]);
        $from = $section->addTextRun();
        if ($payment->isOther()) {
            // An Other receipt (bank interest and the like): what it is, and the account it went to.
            self::addRun($from, ((string) $payment->notes)."\n", ['bold' => true]);
            self::addRun($from, "Account: {$payment->glAccount?->code} {$payment->glAccount?->name}");
        } else {
            self::addRun($from, ($customer?->name ?? '')."\n", ['bold' => true]);
            if ($customer?->uen) {
                self::addRun($from, "UEN: {$customer->uen}");
            }
        }

        $section->addTextBreak();
        $section->addText('Amount Received: SGD '.self::money($payment->amount_sgd), ['bold' => true]);

        $allocations = $payment->allocations;
        if (count($allocations) > 0) {
            $section->addText('Applied To', ['italic' => true]);
            $table = $section->addTable(self::GRID_STYLE);
            $table->addRow();
            self::cell($table, 5000, 'Invoice', true);
            self::cell($table, 2500, 'Amount ($)', true);
            foreach ($allocations as $allocation) {
                $table->addRow();
                self::cell($table, 5000, (string) ($invoiceNumbers[$allocation->invoice_id] ?? ''));
                self::cell($table, 2500, self::money($allocation->amount_sgd));
            }
        }

        $unallocated = $payment->unallocatedSgd()->toFloat();
        if ($unallocated > 0) {
            $section->addTextBreak();
            $section->addText('Unallocated (on account): SGD '.self::money($unallocated));
        }

        return self::toBytes($doc);
    }

    /**
     * Same layout as frontend/src/pages/PurchaseOrderPrintPage.tsx --
     * also what "Email PO" (2026-09-12) converts to PDF and attaches.
     */
    public static function purchaseOrderToDocx(PurchaseOrder $po, ?CompanyIndividual $supplier, ?Company $company): string
    {
        [$doc, $section] = self::newDocument();

        $section->addText((string) $company?->name, ['bold' => true]);
        if ($company?->address) {
            $section->addText($company->address);
        }
        if ($company?->phone) {
            $section->addText("Tel: {$company->phone}");
        }
        if ($company?->uen) {
            $section->addText("Business Reg# {$company->uen}");
        }
        if ($company?->gst_registration_no) {
            $section->addText("GST Reg# {$company->gst_registration_no}");
        }

        self::addTitle($section, 'PURCHASE ORDER');

        $meta = $section->addTextRun();
        self::addRun($meta, "{$po->po_number}\n", ['bold' => true]);
        self::addRun($meta, 'Date: '.self::docDate($po->order_date)."\n");
        self::addRun($meta, 'Status: '.ucwords(str_replace('_', ' ', (string) $po->status))."\n");

        $section->addText('Supplier', ['italic' => true]);
        $to = $section->addTextRun();
        self::addRun($to, ($supplier?->name ?? '')."\n", ['bold' => true]);
        if ($supplier?->gst_registration_no) {
            self::addRun($to, "GST Reg# {$supplier->gst_registration_no}\n");
        }
        if ($supplier?->billing_email) {
            self::addRun($to, "Email: {$supplier->billing_email}\n");
        }
        if ($supplier?->phone) {
            self::addRun($to, "Tel: {$supplier->phone}\n");
        }
        $address = self::addressLine($supplier);
        if ($address !== '') {
            self::addRun($to, $address);
        }

        $table = $section->addTable(self::GRID_STYLE);
        $table->addRow();
        self::cell($table, 6000, 'Description', true);
        self::cell($table, 2500, 'Amount ($)', true);
        $table->addRow();
        self::cell($table, 6000, (string) $po->description);
        self::cell($table, 2500, self::money($po->amount_sgd));

        $section->addTextBreak();
        self::addTotalsTable($section, [
            ['GST', self::money($po->gst_amount_sgd)],
            ['Grand Total (SGD)', self::money($po->total_amount_sgd)],
        ]);

        $section->addTextBreak();
        $section->addText('Please confirm receipt of this purchase order and quote the PO number '
            .'above on your invoice.');
        $section->addTextBreak();
        $section->addText('Authorised by: ______________________________');

        return self::toBytes($doc);
    }

    /**
     * @param  array<string, string>  $billNumbers  supplier invoice id => bill number
     */
    public static function paymentVoucherToDocx(SupplierPayment $payment, ?CompanyIndividual $supplier, ?Company $company, array $billNumbers): string
    {
        [$doc, $section] = self::newDocument();

        $section->addText((string) $company?->name, ['bold' => true]);
        if ($company?->address) {
            $section->addText($company->address);
        }
        if ($company?->phone) {
            $section->addText("Tel: {$company->phone}");
        }
        if ($company?->uen) {
            $section->addText("Business Reg# {$company->uen}");
        }

        self::addTitle($section, 'PAYMENT VOUCHER');

        $meta = $section->addTextRun();
        self::addRun($meta, "{$payment->voucher_number}\n", ['bold' => true]);
        self::addRun($meta, 'Date: '.self::docDate($payment->payment_date)."\n");
        self::addRun($meta, "Method: {$payment->method}\n");
        if ($payment->reference) {
            self::addRun($meta, "Reference: {$payment->reference}\n");
        }

        $section->addText('Paid To', ['italic' => true]);
        $to = $section->addTextRun();
        if ($payment->isOther()) {
            // An Other payment (bank charges and the like): what it is, and the account it was charged to.
            self::addRun($to, ((string) $payment->notes)."\n", ['bold' => true]);
            self::addRun($to, "Account: {$payment->glAccount?->code} {$payment->glAccount?->name}");
        } else {
            self::addRun($to, ($supplier?->name ?? '')."\n", ['bold' => true]);
            if ($supplier?->gst_registration_no) {
                self::addRun($to, "GST Reg# {$supplier->gst_registration_no}");
            }
        }

        $section->addTextBreak();
        $section->addText('Amount Paid: SGD '.self::money($payment->amount_sgd), ['bold' => true]);

        $allocations = $payment->allocations;
        if (count($allocations) > 0) {
            $section->addText('Applied To', ['italic' => true]);
            $table = $section->addTable(self::GRID_STYLE);
            $table->addRow();
            self::cell($table, 5000, 'Bill', true);
            self::cell($table, 2500, 'Amount ($)', true);
            foreach ($allocations as $allocation) {
                $table->addRow();
                self::cell($table, 5000, (string) ($billNumbers[$allocation->supplier_invoice_id] ?? ''));
                self::cell($table, 2500, self::money($allocation->amount_sgd));
            }
        }

        $unallocated = $payment->unallocatedSgd()->toFloat();
        if ($unallocated > 0) {
            $section->addTextBreak();
            $section->addText('Unallocated: SGD '.self::money($unallocated));
        }

        return self::toBytes($doc);
    }

    /**
     * Same layout as frontend/src/pages/ServiceRecordPrintPage.tsx --
     * also what "Email" (2026-09-12) converts to PDF and attaches.
     */
    public static function serviceRecordToDocx(ServiceRecord $record, ?JobOrder $jobOrder, ?CompanyIndividual $customer, ?Company $company): string
    {
        [$doc, $section] = self::newDocument();

        $section->addText((string) $company?->name, ['bold' => true]);
        if ($company?->address) {
            $section->addText($company->address);
        }
        if ($company?->phone) {
            $section->addText("Tel: {$company->phone}");
        }

        self::addTitle($section, 'SERVICE RECORD');

        $meta = $section->addTextRun();
        self::addRun($meta, "{$record->service_record_number}\n", ['bold' => true]);
        self::addRun($meta, "Job Order: {$jobOrder?->job_order_number} -- {$jobOrder?->subject}\n");
        self::addRun($meta, 'Work Date: '.self::docDate($record->work_date)."\n");
        self::addRun($meta, 'Status: '.ucwords((string) $record->status)."\n");

        $section->addText('Company / Individual', ['italic' => true]);
        $to = $section->addTextRun();
        self::addRun($to, (string) ($customer?->name ?? ''));

        $table = $section->addTable(self::GRID_STYLE);
        $table->addRow();
        self::cell($table, 5000, 'Field', true);
        self::cell($table, 4000, 'Value', true);
        $rows = [
            ['Time logged (raw)', "{$record->raw_minutes} min"],
            ['Time logged (rounded, SRV-007)', "{$record->rounded_minutes} min"],
            ['Completion', $record->completion_status === 'C'
                ? 'Completed'
                : 'Not yet completed -- another visit expected'],
            ['After hours / weekend / holiday', $record->is_after_hours ? 'Yes' : 'No'],
        ];
        if ($record->deducted_minutes !== null) {
            $rows[] = ['Minutes deducted from contract', "{$record->deducted_minutes} min"];
        }
        foreach ($rows as [$label, $value]) {
            $table->addRow();
            self::cell($table, 5000, $label);
            self::cell($table, 4000, $value);
        }

        $section->addTextBreak();
        $section->addText('Signature & Company Stamp: ______________________________');

        return self::toBytes($doc);
    }

    /**
     * `$statement` is the array built by
     * AccountsReceivableService::buildCustomerStatement() -- the same
     * shape as Python's CompanyIndividualStatement schema, accepted as
     * a plain array (Python takes it duck-typed for the same reason:
     * this service must not depend on the API schema layer). Same
     * layout as frontend/src/pages/StatementPrintPage.tsx; also what
     * "Email" converts to PDF and attaches (2026-09-12).
     *
     * @param  array<string, mixed>  $statement
     */
    public static function statementToDocx(array $statement, ?CompanyIndividual $customer, ?Company $company): string
    {
        [$doc, $section] = self::newDocument();

        $section->addText((string) $company?->name, ['bold' => true]);
        if ($company?->address) {
            $section->addText($company->address);
        }
        if ($company?->gst_registration_no) {
            $section->addText("GST Reg# {$company->gst_registration_no}");
        }

        self::addTitle($section, 'STATEMENT OF ACCOUNTS');

        $meta = $section->addTextRun();
        self::addRun($meta, ($customer?->name ?? '')."\n", ['bold' => true]);
        self::addRun($meta, 'As at: '.self::docDate($statement['as_at'])."\n");
        if (($statement['payment_terms_days'] ?? null) !== null) {
            self::addRun($meta, "Payment terms: Net {$statement['payment_terms_days']} days\n");
        }

        $table = $section->addTable(self::GRID_STYLE);
        $table->addRow();
        self::cell($table, 2400, 'Invoice', true);
        self::cell($table, 1600, 'Issued', true);
        self::cell($table, 1600, 'Due', true);
        self::cell($table, 1700, 'Total ($)', true);
        self::cell($table, 1900, 'Outstanding ($)', true);
        foreach ($statement['lines'] as $line) {
            $table->addRow();
            self::cell($table, 2400, $line['invoice_number'].($line['is_disputed'] ? ' (disputed)' : '')
                .(($line['credited_sgd'] ?? 0) > 0 ? ' (credited '.self::money($line['credited_sgd']).')' : ''));
            self::cell($table, 1600, self::docDate($line['issued_on']));
            self::cell($table, 1600, $line['due_date'] !== null ? self::docDate($line['due_date']) : '-');
            self::cell($table, 1700, self::money($line['total_amount_sgd']));
            self::cell($table, 1900, self::money($line['outstanding_sgd']));
        }

        $section->addTextBreak();
        $section->addText('Total Outstanding: SGD '.self::money($statement['total_outstanding_sgd']), ['bold' => true]);
        if ($statement['unallocated_credit_sgd'] > 0) {
            $section->addText('Unallocated credit on account: SGD '.self::money($statement['unallocated_credit_sgd']));
        }

        return self::toBytes($doc);
    }

    /**
     * The unstyled two-column totals block every form closes with; the
     * "Grand Total" row is bolded, matching the Python loop's own
     * `label.startswith("Grand Total")` check.
     *
     * @param  array<int, array{0: string, 1: string}>  $rows
     */
    private static function addTotalsTable(Section $section, array $rows): void
    {
        $totals = $section->addTable();
        foreach ($rows as [$label, $value]) {
            $bold = str_starts_with($label, 'Grand Total');
            $totals->addRow();
            self::cell($totals, 5000, $label, $bold);
            self::cell($totals, 2500, $value, $bold);
        }
    }
}
