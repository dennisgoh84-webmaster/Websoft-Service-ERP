<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Invoice;
use App\Services\Authority;
use Illuminate\Http\Request;

/**
 * Sales invoices. Mirrors backend/app/routers/billing.py -- see
 * App\Services\BillingService for the BILL-001/002/005 and SRV-008
 * business logic that issues these (from ContractController::activate()
 * and ExcessUsageService::decideExcessUsage()); this controller only
 * lists/reads what's already been issued.
 *
 * NOT yet converted from the Python router: CSV/Excel export, the
 * .docx export and "Email Invoice" endpoints (both need the Documents
 * module's mailer/docx-generation wiring, same gap as Service
 * Records).
 *
 * `gl_status` is always reported "not_posted" -- see
 * App\Services\BillingService's class docblock for the GL posting
 * known gap. This matches InvoiceOut's own Python default, so a
 * not-yet-converted GL step is never misreported as posted.
 */
class InvoiceController extends Controller
{
    private const MODULE = 'billing';

    private function present(Invoice $invoice): array
    {
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
            'gl_status' => 'not_posted',
            'gl_voucher_number' => null,
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
}
