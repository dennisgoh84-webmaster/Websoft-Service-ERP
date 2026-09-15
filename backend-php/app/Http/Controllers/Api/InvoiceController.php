<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Concerns\SendsDocuments;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Invoice;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\DocxForms;
use App\Services\Posting;
use Illuminate\Http\Request;

/**
 * Sales invoices. Mirrors backend/app/routers/billing.py -- see
 * App\Services\BillingService for the BILL-001/002/005 and SRV-008
 * business logic that issues these (from ContractController::activate()
 * and ExcessUsageService::decideExcessUsage()); this controller only
 * lists/reads what's already been issued.
 *
 * NOT yet converted from the Python router: CSV/Excel export. (The
 * .docx export and "Email Invoice" endpoints WERE the other gap here;
 * both are converted now -- see exportDocx()/email() below and
 * App\Services\DocxForms / App\Services\DocumentEmail.)
 */
class InvoiceController extends Controller
{
    use SendsDocuments;

    private const MODULE = 'billing';

    private function present(Invoice $invoice): array
    {
        $glEntry = Posting::liveEntryFor(Posting::SOURCE_INVOICE, $invoice->id);

        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'customer_id' => $invoice->customer_id,
            'contract_id' => $invoice->contract_id,
            'invoice_type' => $invoice->invoice_type,
            'description' => $invoice->description,
            'amount_sgd' => (float) $invoice->amount_sgd,
            'tax_code' => $invoice->tax_code,
            'gst_rate' => (float) $invoice->gst_rate,
            'gst_amount_sgd' => (float) $invoice->gst_amount_sgd,
            'total_amount_sgd' => (float) $invoice->total_amount_sgd,
            'amount_paid_sgd' => (float) $invoice->amount_paid_sgd,
            'outstanding_sgd' => $invoice->outstandingSgd()->toFloat(),
            'due_date' => optional($invoice->due_date)->toDateString(),
            'status' => $invoice->status,
            'is_disputed' => $invoice->is_disputed,
            'dispute_note' => $invoice->dispute_note,
            'issued_at' => optional($invoice->issued_at)->toIso8601String(),
            'gl_status' => $glEntry ? 'posted' : 'not_posted',
            'gl_voucher_number' => $glEntry?->voucher_number,
        ];
    }

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $query = Invoice::where('company_id', $user->company_id);
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }
        if ($request->filled('contract_id')) {
            $query->where('contract_id', $request->query('contract_id'));
        }

        return $query->orderByDesc('issued_at')->get()->map(fn (Invoice $i) => $this->present($i))->values();
    }

    public function show(Request $request, string $invoiceId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $invoice = Invoice::find($invoiceId);
        if (! $invoice || $invoice->company_id !== $user->company_id) {
            throw new ApiException(404, 'Invoice not found');
        }

        return response()->json($this->present($invoice));
    }

    private function invoiceOrFail(string $companyId, string $invoiceId): Invoice
    {
        $invoice = Invoice::find($invoiceId);
        if (! $invoice || $invoice->company_id !== $companyId) {
            throw new ApiException(404, 'Invoice not found');
        }

        return $invoice;
    }

    /** GET /invoices/{id}/export.docx -- the Word button on the Invoice print page. */
    public function exportDocx(Request $request, string $invoiceId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $invoice = $this->invoiceOrFail($user->company_id, $invoiceId);
        $customer = CompanyIndividual::find($invoice->customer_id);
        $company = Company::find($user->company_id);

        return $this->docxResponse(
            DocxForms::invoiceToDocx($invoice, $customer, $company),
            "{$invoice->invoice_number}.docx",
        );
    }

    /**
     * Email Sales Invoice (2026-09-12) -- same real-send pattern as
     * Purchase Order's Email button.
     */
    public function email(Request $request, string $invoiceId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $invoice = $this->invoiceOrFail($user->company_id, $invoiceId);
        $customer = CompanyIndividual::find($invoice->customer_id);
        if (! $customer || ! $customer->billing_email) {
            throw new ApiException(422, 'This customer has no email on file -- add one on the Company/Individual page first.');
        }
        $company = Company::find($user->company_id);
        $companyName = $this->companyName($company);
        $docxBytes = DocxForms::invoiceToDocx($invoice, $customer, $company);
        $total = number_format((float) $invoice->total_amount_sgd, 2, '.', '');
        $body = "Dear {$customer->name},\n\n"
            ."Please find attached Invoice {$invoice->invoice_number} for SGD {$total}"
            .($invoice->due_date ? ', due '.$invoice->due_date->toDateString().".\n\n" : ".\n\n")
            ."Regards,\n{$companyName}";

        $result = $this->emailDocument(
            $customer->billing_email,
            "Invoice {$invoice->invoice_number} - {$companyName}",
            $body,
            $docxBytes,
            $invoice->invoice_number,
        );

        Audit::record(
            entityType: 'invoice',
            entityId: $invoice->id,
            action: 'emailed',
            actorUserId: $user->id,
            details: "{$invoice->invoice_number} emailed to {$customer->billing_email}",
        );

        return response()->json($result);
    }
}
