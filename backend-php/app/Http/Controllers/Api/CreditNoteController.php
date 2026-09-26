<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Exceptions\ARRuleViolation;
use App\Http\Controllers\Api\Concerns\SendsDocuments;
use App\Http\Controllers\Api\Concerns\SendsExports;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\Authority;
use App\Services\CreditNotes;
use App\Services\DocxForms;
use App\Services\Posting;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Credit Notes (BILL-003) -- the rules are in App\Services\CreditNotes.
 * Same module as Sales Invoices (`billing`): VIEW lists them, EDIT
 * raises one; who may approve is BILL-003's, by role and limit.
 */
class CreditNoteController extends Controller
{
    use SendsDocuments;
    use SendsExports;

    private const MODULE = 'billing';

    private const EXPORT_FIELDS = [
        'credit_note_number', 'invoice_number', 'customer_name', 'reason', 'amount_sgd', 'gst_amount_sgd',
        'total_amount_sgd', 'status', 'raised_at', 'issued_at',
    ];

    private function noteOrFail(string $companyId, string $id): CreditNote
    {
        $note = CreditNote::with(['invoice', 'customer'])->find($id);
        if (! $note || $note->company_id !== $companyId) {
            throw new ApiException(404, 'Credit note not found');
        }

        return $note;
    }

    private function present(CreditNote $n, User $viewer): array
    {
        $gl = $n->status === CreditNote::STATUS_ISSUED ? Posting::liveEntryFor(Posting::SOURCE_CREDIT_NOTE, $n->id) : null;
        $limit = $n->customer?->credit_note_approval_limit_sgd;

        return [
            'id' => $n->id,
            'credit_note_number' => $n->credit_note_number,
            'invoice_id' => $n->invoice_id,
            'invoice_number' => $n->invoice?->invoice_number,
            'customer_id' => $n->customer_id,
            'customer_name' => $n->customer?->name,
            'reason' => $n->reason,
            'amount_sgd' => (float) $n->amount_sgd,
            'tax_code' => $n->tax_code,
            'gst_rate' => $n->gst_rate === null ? null : (float) $n->gst_rate,
            'gst_amount_sgd' => (float) $n->gst_amount_sgd,
            'total_amount_sgd' => (float) $n->total_amount_sgd,
            'currency_code' => $n->currencyCode(),
            'exchange_rate' => (float) $n->rate(),
            'amount_fx' => $n->fx('amount')->toFloat(),
            'gst_amount_fx' => $n->fx('gst_amount')->toFloat(),
            'total_amount_fx' => $n->fx('total_amount')->toFloat(),
            'status' => $n->status,
            'raised_by' => $n->raisedBy?->full_name,
            'raised_at' => $n->raised_at?->toIso8601String(),
            'decided_by' => $n->decidedBy?->full_name,
            'decided_at' => $n->decided_at?->toIso8601String(),
            'decision_note' => $n->decision_note,
            'issued_at' => $n->issued_at?->toIso8601String(),
            // BILL-003: who approves this one, and whether the viewer may.
            'credit_note_limit_sgd' => $limit === null ? null : (float) $limit,
            'needs_owner' => CreditNotes::needsOwner($n),
            'can_approve' => $n->status === CreditNote::STATUS_PENDING && CreditNotes::canApprove($n, $viewer),
            'gl_status' => $gl ? 'posted' : 'not_posted',
            'gl_voucher_number' => $gl?->voucher_number,
            // 2026-09-26: the lines it credits, and credit left on the customer's account.
            'lines' => $n->lines->map(fn ($l) => [
                'invoice_line_id' => $l->invoice_line_id, 'description' => $l->description, 'quantity' => $l->quantity,
                'amount_fx' => (float) $l->amount_fx, 'amount_sgd' => (float) $l->amount_sgd,
                'return_to_stock' => $l->return_to_stock, 'warehouse_id' => $l->warehouse_id,
            ])->values(),
            'unapplied_fx' => $n->unappliedFx()->toFloat(),
            'unapplied_sgd' => $n->unappliedSgd()->toFloat(),
            'applications' => $n->applications->map(fn ($a) => [
                'kind' => $a->kind, 'invoice_id' => $a->invoice_id, 'invoice_number' => $a->invoice?->invoice_number,
                'supplier_payment_id' => $a->supplier_payment_id,
                'voucher_number' => $a->supplier_payment_id ? SupplierPayment::whereKey($a->supplier_payment_id)->value('voucher_number') : null,
                'amount_fx' => (float) $a->amount_fx, 'applied_at' => $a->applied_at?->toIso8601String(),
            ])->values(),
        ];
    }

    /** @return Collection<int, CreditNote> */
    private function filtered(string $companyId, Request $request): Collection
    {
        return CreditNote::with(['invoice', 'customer', 'raisedBy', 'decidedBy'])
            ->where('company_id', $companyId)
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('customer_id'), fn ($q, $c) => $q->where('customer_id', $c))
            ->when($request->query('invoice_id'), fn ($q, $i) => $q->where('invoice_id', $i))
            ->orderByDesc('raised_at')
            ->get();
    }

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->filtered($user->company_id, $request)->map(fn (CreditNote $n) => $this->present($n, $user))->values();
    }

    public function show(Request $request, string $id)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return response()->json($this->present($this->noteOrFail($user->company_id, $id), $user));
    }

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');
        $data = $request->validate([
            'invoice_id' => 'required|uuid',
            // Net of GST, in the invoice's own currency (multi-currency);
            // `amount_sgd` is kept for an SGD invoice.
            'amount' => 'nullable|numeric|gt:0',
            'amount_sgd' => 'nullable|numeric|gt:0',
            'reason' => 'required|string|max:1000',
            // Or whole lines / part quantities, with goods returned (2026-09-26).
            'lines' => 'sometimes|array',
            'lines.*.invoice_line_id' => 'required_with:lines|uuid',
            'lines.*.quantity' => 'required_with:lines|integer|min:0',
            'lines.*.return_to_stock' => 'sometimes|boolean',
            'lines.*.warehouse_id' => 'sometimes|nullable|uuid',
        ]);
        if (empty($data['lines']) && empty($data['amount']) && empty($data['amount_sgd'])) {
            throw new ApiException(422, 'Give an amount to credit, or the lines and quantities.');
        }
        $invoice = Invoice::find($data['invoice_id']);
        if (! $invoice || $invoice->company_id !== $user->company_id) {
            throw new ApiException(404, 'Invoice not found');
        }

        try {
            $amount = $data['amount'] ?? $data['amount_sgd'] ?? null;
            $note = CreditNotes::raise($invoice, $user, $amount === null ? null : (string) $amount, $data['reason'], $data['lines'] ?? []);
        } catch (ARRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->present($note->fresh(['invoice', 'customer']), $user));
    }

    /** Set credit left on the customer's account against another of their invoices. */
    public function apply(Request $request, string $id)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');
        $data = $request->validate(['invoice_id' => 'required|uuid', 'amount' => 'required|numeric|gt:0']);
        $note = $this->noteOrFail($user->company_id, $id);
        $invoice = Invoice::where('company_id', $user->company_id)->find($data['invoice_id']);
        if (! $invoice) {
            throw new ApiException(404, 'Invoice not found');
        }
        try {
            CreditNotes::apply($note, $invoice, Money::of($data['amount']), $user);
        } catch (ARRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->present($note->fresh(), $user));
    }

    /** Refund credit left on the customer's account with a Payment Voucher. */
    public function refund(Request $request, string $id)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');
        Authority::requireModuleAccess($user, 'accounts_payable', 'edit');
        $data = $request->validate([
            'bank_account_id' => 'required|uuid',
            'payment_date' => 'required|date',
            'amount' => 'sometimes|nullable|numeric|gt:0',
            'reference' => 'sometimes|nullable|string|max:200',
        ]);
        $note = $this->noteOrFail($user->company_id, $id);
        try {
            $pv = CreditNotes::refund($note, $data['bank_account_id'], $data['payment_date'],
                isset($data['amount']) ? Money::of($data['amount']) : null, $user, $data['reference'] ?? null);
        } catch (ARRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->present($note->fresh(), $user) + ['refund_voucher_number' => $pv->voucher_number]);
    }

    public function approve(Request $request, string $id)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');
        $note = $this->noteOrFail($user->company_id, $id);
        try {
            $note = CreditNotes::approve($note, $user);
        } catch (ARRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->present($note, $user));
    }

    public function reject(Request $request, string $id)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');
        $data = $request->validate(['reason' => 'required|string|max:1000']);
        try {
            $note = CreditNotes::reject($this->noteOrFail($user->company_id, $id), $user, $data['reason']);
        } catch (ARRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->present($note, $user));
    }

    public function withdraw(Request $request, string $id)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');
        try {
            $note = CreditNotes::withdraw($this->noteOrFail($user->company_id, $id), $user);
        } catch (ARRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->present($note, $user));
    }

    /** @return array<int, array<string, mixed>> */
    private function exportRows(string $companyId, Request $request): array
    {
        return $this->filtered($companyId, $request)->map(fn (CreditNote $n) => [
            'credit_note_number' => $n->credit_note_number ?? '',
            'invoice_number' => $n->invoice?->invoice_number,
            'customer_name' => $n->customer?->name,
            'reason' => $n->reason,
            'amount_sgd' => number_format((float) $n->amount_sgd, 2, '.', ''),
            'gst_amount_sgd' => number_format((float) $n->gst_amount_sgd, 2, '.', ''),
            'total_amount_sgd' => number_format((float) $n->total_amount_sgd, 2, '.', ''),
            'status' => $n->status,
            'raised_at' => $n->raised_at?->setTimezone(config('app.timezone'))->toDateString(),
            'issued_at' => $n->issued_at?->setTimezone(config('app.timezone'))->toDateString() ?? '',
        ])->all();
    }

    public function exportCsv(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->csvResponse(self::EXPORT_FIELDS, $this->exportRows($user->company_id, $request), 'credit-notes.csv');
    }

    public function exportExcel(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->xlsxResponse(self::EXPORT_FIELDS, $this->exportRows($user->company_id, $request), 'Credit Notes', 'credit-notes.xlsx');
    }

    /** GET /credit-notes/{id}/export.docx -- the Word button on the print page; only an issued one is a document. */
    public function exportDocx(Request $request, string $id)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');
        $note = $this->noteOrFail($user->company_id, $id);
        if ($note->status !== CreditNote::STATUS_ISSUED) {
            throw new ApiException(409, 'Only an issued credit note has a document to print.');
        }

        return $this->docxResponse(
            DocxForms::creditNoteToDocx($note, $note->customer, Company::find($user->company_id)),
            "{$note->credit_note_number}.docx",
        );
    }
}
