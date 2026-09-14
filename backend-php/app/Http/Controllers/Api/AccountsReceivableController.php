<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Exceptions\ARRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Invoice;
use App\Services\AccountsReceivableService;
use App\Services\Audit;
use App\Services\Authority;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Accounts Receivable. Mirrors
 * backend/app/routers/accounts_receivable.py -- see
 * App\Services\AccountsReceivableService for the AR-002/003 business
 * logic this only orchestrates.
 *
 * NOT yet converted from the Python router (tracked in
 * docs/php-conversion-plan.md): everything Payment-related (POST
 * /payments, allocate, statements, CSV/Excel/.docx export, "Email
 * Receipt"/"Email Statement") -- see AccountsReceivableService's class
 * docblock for why (needs `bank_accounts`, from the still-pending GL
 * posting + Bank module). Also not converted: the commission clawback
 * that Python's write-off endpoint triggers (Commission Management is
 * deferred, per CLAUDE.md) and the un-GL/un-bank endpoints (both GL
 * posting territory).
 */
class AccountsReceivableController extends Controller
{
    private const MODULE = 'accounts_receivable';

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

    private function invoiceOrFail(string $companyId, string $invoiceId): Invoice
    {
        $invoice = Invoice::find($invoiceId);
        if (! $invoice || $invoice->company_id !== $companyId) {
            throw new ApiException(404, 'Invoice not found');
        }

        return $invoice;
    }

    /** AR-002. The invoice is marked written off, never deleted. */
    public function writeOffInvoice(Request $request, string $invoiceId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $invoice = $this->invoiceOrFail($user->company_id, $invoiceId);
        $data = $request->validate(['reason' => 'required|string|min:1']);
        $outstanding = $invoice->outstandingSgd();

        try {
            DB::transaction(function () use ($invoice, $user, $data, $outstanding) {
                AccountsReceivableService::writeOffInvoice($invoice, $user, $data['reason']);

                Audit::record(
                    entityType: 'invoice',
                    entityId: $invoice->id,
                    action: 'written_off',
                    actorUserId: $user->id,
                    reason: $data['reason'],
                    details: "{$invoice->invoice_number}, SGD {$outstanding->toString()} written off as bad debt",
                    oldValue: ['status' => 'outstanding', 'outstanding_sgd' => $outstanding->toString()],
                    newValue: ['status' => 'written_off', 'outstanding_sgd' => '0.00'],
                );

                // NOT converted: commission clawback (Commission
                // Management is deferred, per CLAUDE.md) -- see this
                // controller's class docblock.
            });
        } catch (ARRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->present($invoice->fresh()));
    }

    /**
     * AR-003: records that an invoice is disputed. Deliberately does
     * NOT hold collections -- the invoice keeps aging normally.
     */
    public function flagDispute(Request $request, string $invoiceId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $invoice = $this->invoiceOrFail($user->company_id, $invoiceId);
        $data = $request->validate([
            'is_disputed' => 'required|boolean',
            'note' => 'sometimes|nullable|string',
        ]);
        $was = $invoice->is_disputed;

        $invoice->is_disputed = $data['is_disputed'];
        $invoice->dispute_note = $data['note'] ?? null;
        $invoice->save();

        Audit::record(
            entityType: 'invoice',
            entityId: $invoice->id,
            action: $data['is_disputed'] ? 'dispute_flagged' : 'dispute_cleared',
            actorUserId: $user->id,
            reason: $data['note'] ?? null,
            details: "{$invoice->invoice_number} (AR-003: collections continue regardless)",
            oldValue: ['is_disputed' => $was],
            newValue: ['is_disputed' => $data['is_disputed']],
        );

        return response()->json($this->present($invoice->fresh()));
    }

    /**
     * Outstanding balances bucketed by how far past due they are.
     * AR-003: disputed invoices are included like any other -- they
     * are flagged in the statement, not excluded from collections.
     */
    public function agingReport(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $asAt = $request->filled('as_at') ? Carbon::parse($request->query('as_at')) : null;
        [$resolvedAsAt, $rows] = AccountsReceivableService::agingRows($user->company_id, $asAt);

        return response()->json([
            'as_at' => $resolvedAsAt->toDateString(),
            'rows' => $rows,
            'current' => array_sum(array_column($rows, 'current')),
            'days_1_30' => array_sum(array_column($rows, 'days_1_30')),
            'days_31_60' => array_sum(array_column($rows, 'days_31_60')),
            'days_61_90' => array_sum(array_column($rows, 'days_61_90')),
            'over_90' => array_sum(array_column($rows, 'over_90')),
            'total' => array_sum(array_column($rows, 'total')),
        ]);
    }
}
