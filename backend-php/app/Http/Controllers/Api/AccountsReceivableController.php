<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Exceptions\ARRuleViolation;
use App\Exceptions\PostingError;
use App\Http\Controllers\Api\Concerns\SendsDocuments;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Invoice;
use App\Services\AccountsReceivableService;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\CommissionService;
use App\Services\DocxForms;
use App\Services\Posting;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Accounts Receivable (invoice-side actions). Mirrors
 * backend/app/routers/accounts_receivable.py -- see
 * App\Services\AccountsReceivableService for the AR-002/003 business
 * logic this only orchestrates. The AR-001 payment/receipt endpoints
 * live in App\Http\Controllers\Api\PaymentController.
 *
 * NOT yet converted from the Python router (tracked in
 * docs/php-conversion-plan.md): CSV/Excel export. The other two gaps
 * recorded here are both closed now -- the Customer Statement
 * endpoints (JSON, .docx and Email: see statement()/statementDocx()/
 * statementEmail() below), and the commission clawback the write-off
 * endpoint triggers (see writeOffInvoice() and
 * App\Services\CommissionService::createClawback()).
 */
class AccountsReceivableController extends Controller
{
    use SendsDocuments;

    private const MODULE = 'accounts_receivable';

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

                // Commission clawback (6.4): commission already
                // earned on receipts against this invoice is reversed
                // by a NEGATIVE payout record, never by editing the
                // earning it reverses. Null when nothing was earned
                // (no rate set, no salesperson on the contract, or no
                // receipts allocated).
                $clawback = CommissionService::createClawback(
                    $user->company_id,
                    $invoice,
                    $user->id,
                    "Write-off of {$invoice->invoice_number}: {$data['reason']}",
                );
                if ($clawback !== null) {
                    Audit::record(
                        entityType: 'commission_payout',
                        entityId: $clawback->id,
                        action: 'clawback_created',
                        actorUserId: $user->id,
                        newValue: [
                            'payout_number' => $clawback->payout_number,
                            'amount_sgd' => (float) $clawback->amount_sgd,
                            'invoice' => $invoice->invoice_number,
                        ],
                    );
                }
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

    /** ACC-004: UNGL -- reverse this invoice's GL posting. */
    public function unglInvoice(Request $request, string $invoiceId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $invoice = $this->invoiceOrFail($user->company_id, $invoiceId);
        $data = $request->validate(['reason' => 'required|string|min:1']);

        try {
            $reversal = Posting::unpost(Posting::SOURCE_INVOICE, $invoice->id, $user->id, $data['reason'], 'invoice');
        } catch (PostingError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json(['status' => 'reversed', 'reversal_voucher' => $reversal->voucher_number]);
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

    private function customerOrFail(string $companyId, string $customerId): CompanyIndividual
    {
        $customer = CompanyIndividual::find($customerId);
        if (! $customer || $customer->company_id !== $companyId) {
            throw new ApiException(404, 'Company / Individual not found');
        }

        return $customer;
    }

    /**
     * Everything this customer currently owes, plus any receipt money
     * still sitting unallocated on their account.
     */
    public function statement(Request $request, string $customerId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $customer = $this->customerOrFail($user->company_id, $customerId);
        $asAt = $request->filled('as_at') ? Carbon::parse($request->query('as_at')) : null;

        return response()->json(
            AccountsReceivableService::buildCustomerStatement($customer, $user->company_id, $asAt)
        );
    }

    /** GET /accounts-receivable/statement/{customer}/export.docx -- the Word button on the Statement print page. */
    public function statementDocx(Request $request, string $customerId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $customer = $this->customerOrFail($user->company_id, $customerId);
        $asAt = $request->filled('as_at') ? Carbon::parse($request->query('as_at')) : null;
        $statement = AccountsReceivableService::buildCustomerStatement($customer, $user->company_id, $asAt);
        $company = Company::find($user->company_id);

        return $this->docxResponse(
            DocxForms::statementToDocx($statement, $customer, $company),
            "Statement-{$customer->name}-{$statement['as_at']}.docx",
        );
    }

    /**
     * Email Statement of Accounts (2026-09-12) -- same real-send
     * pattern as Purchase Order's Email button.
     */
    public function statementEmail(Request $request, string $customerId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $customer = $this->customerOrFail($user->company_id, $customerId);
        if (! $customer->billing_email) {
            throw new ApiException(422, 'This customer has no email on file -- add one on the Company/Individual page first.');
        }
        $asAt = $request->filled('as_at') ? Carbon::parse($request->query('as_at')) : null;
        $statement = AccountsReceivableService::buildCustomerStatement($customer, $user->company_id, $asAt);
        $company = Company::find($user->company_id);
        $companyName = $this->companyName($company);
        $docxBytes = DocxForms::statementToDocx($statement, $customer, $company);
        $total = number_format((float) $statement['total_outstanding_sgd'], 2, '.', '');
        $body = "Dear {$customer->name},\n\n"
            ."Please find attached your Statement of Accounts as at {$statement['as_at']}, "
            ."total outstanding SGD {$total}.\n\n"
            ."Regards,\n{$companyName}";

        $result = $this->emailDocument(
            $company,
            $customer->billing_email,
            "Statement of Accounts as at {$statement['as_at']} - {$companyName}",
            $body,
            $docxBytes,
            "Statement-{$customer->name}-{$statement['as_at']}",
        );

        Audit::record(
            entityType: 'customer',
            entityId: $customer->id,
            action: 'statement_emailed',
            actorUserId: $user->id,
            details: "Statement as at {$statement['as_at']} emailed to {$customer->billing_email}",
        );

        return response()->json($result);
    }
}
