<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Exceptions\CurrencyRuleViolation;
use App\Exceptions\PayablesRuleViolation;
use App\Exceptions\PostingError;
use App\Http\Controllers\Api\Concerns\SendsExports;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Account;
use App\Models\CompanyIndividual;
use App\Models\SupplierInvoice;
use App\Models\TaxCode;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\Currency;
use App\Services\Numbering;
use App\Services\PayablesService;
use App\Services\Posting;
use App\Services\Tax;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Supplier invoices (bills). Mirrors the bill half of
 * backend/app/routers/payables.py -- see App\Services\PayablesService
 * for the PUR-002/003 business logic this only orchestrates.
 *
 * NOT yet converted: CSV/Excel export.
 */
class SupplierInvoiceController extends Controller
{
    use SendsExports;

    /** @var array<int, string> */
    private const EXPORT_FIELDS = [
        'bill_number', 'supplier_invoice_no', 'supplier_name', 'invoice_date', 'due_date',
        'description', 'amount_sgd', 'gst_amount_sgd', 'total_amount_sgd', 'amount_paid_sgd',
        'outstanding_sgd', 'match_status', 'status',
    ];

    private const MODULE = 'accounts_payable';

    private function supplierOrFail(string $companyId, string $supplierId): CompanyIndividual
    {
        $supplier = CompanyIndividual::find($supplierId);
        if (! $supplier || $supplier->company_id !== $companyId || ! $supplier->is_supplier) {
            throw new ApiException(404, "Supplier not found -- check it exists and is marked 'Is Supplier' on the Company/Individual page.");
        }

        return $supplier;
    }

    private function billOrFail(string $companyId, string $billId): SupplierInvoice
    {
        $bill = SupplierInvoice::find($billId);
        if (! $bill || $bill->company_id !== $companyId) {
            throw new ApiException(404, 'Supplier invoice not found');
        }

        return $bill;
    }

    public function present(SupplierInvoice $bill): array
    {
        $glEntry = Posting::liveEntryFor(Posting::SOURCE_SUPPLIER_INVOICE, $bill->id);

        return [
            'id' => $bill->id,
            'bill_number' => $bill->bill_number,
            'supplier_invoice_no' => $bill->supplier_invoice_no,
            'supplier_id' => $bill->supplier_id,
            'purchase_order_id' => $bill->purchase_order_id,
            'expense_account_id' => $bill->expense_account_id,
            'invoice_date' => optional($bill->invoice_date)->toDateString(),
            'due_date' => optional($bill->due_date)->toDateString(),
            'description' => $bill->description,
            'amount_sgd' => (float) $bill->amount_sgd,
            'tax_code' => $bill->tax_code,
            'gst_rate' => $bill->gst_rate === null ? null : (float) $bill->gst_rate,
            'gst_amount_sgd' => (float) $bill->gst_amount_sgd,
            'total_amount_sgd' => (float) $bill->total_amount_sgd,
            'amount_paid_sgd' => (float) $bill->amount_paid_sgd,
            'outstanding_sgd' => $bill->outstandingSgd()->toFloat(),
            // Multi-currency: the bill's own currency, rate and figures in it.
            'currency_code' => $bill->currencyCode(),
            'exchange_rate' => (float) $bill->rate(),
            'amount_fx' => $bill->fx('amount')->toFloat(),
            'gst_amount_fx' => $bill->fx('gst_amount')->toFloat(),
            'total_amount_fx' => $bill->fx('total_amount')->toFloat(),
            'outstanding_fx' => $bill->outstandingFx()->toFloat(),
            'match_status' => $bill->match_status,
            'match_note' => $bill->match_note,
            'status' => $bill->status,
            'gl_status' => $glEntry ? 'posted' : 'not_posted',
            'gl_voucher_number' => $glEntry?->voucher_number,
        ];
    }

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->filtered($user->company_id, $request)
            ->map(fn (SupplierInvoice $b) => $this->present($b))->values();
    }

    /**
     * The list the screen shows, honouring its filters -- shared with
     * the exports so an Export button always returns what is on
     * screen.
     *
     * @return Collection<int, SupplierInvoice>
     */
    private function filtered(string $companyId, Request $request)
    {
        $query = SupplierInvoice::where('company_id', $companyId);
        foreach (['supplier_id', 'status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }

        return $query->orderByDesc('invoice_date')->get();
    }

    /** @return array<int, array<string, mixed>> */
    private function exportRows(string $companyId, Request $request): array
    {
        $supplierNames = CompanyIndividual::where('company_id', $companyId)->pluck('name', 'id');

        return $this->filtered($companyId, $request)->map(fn (SupplierInvoice $b) => [
            'bill_number' => $b->bill_number,
            'supplier_invoice_no' => $b->supplier_invoice_no ?? '',
            'supplier_name' => $supplierNames[$b->supplier_id] ?? '',
            'invoice_date' => optional($b->invoice_date)->toDateString(),
            'due_date' => optional($b->due_date)->toDateString() ?? '',
            'description' => $b->description,
            'amount_sgd' => number_format((float) $b->amount_sgd, 2, '.', ''),
            'gst_amount_sgd' => number_format((float) $b->gst_amount_sgd, 2, '.', ''),
            'total_amount_sgd' => number_format((float) $b->total_amount_sgd, 2, '.', ''),
            'amount_paid_sgd' => number_format((float) $b->amount_paid_sgd, 2, '.', ''),
            'outstanding_sgd' => $b->outstandingSgd()->toString(),
            'match_status' => $b->match_status,
            'status' => $b->status,
        ])->all();
    }

    public function exportCsv(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->csvResponse(
            self::EXPORT_FIELDS, $this->exportRows($user->company_id, $request), 'supplier-bills.csv'
        );
    }

    public function exportExcel(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->xlsxResponse(
            self::EXPORT_FIELDS, $this->exportRows($user->company_id, $request),
            'Supplier Bills', 'supplier-bills.xlsx'
        );
    }

    public function show(Request $request, string $billId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return response()->json($this->present($this->billOrFail($user->company_id, $billId)));
    }

    /**
     * Enter a supplier invoice. It is 2-way matched against its PO
     * immediately (PUR-002); a match auto-approves it for payment
     * (PUR-003), a mismatch becomes an exception.
     */
    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $data = $request->validate([
            'supplier_id' => 'required|uuid',
            'purchase_order_id' => 'sometimes|nullable|uuid',
            'supplier_invoice_no' => 'sometimes|nullable|string',
            'invoice_date' => 'required|date',
            'description' => 'required|string|min:1',
            // Multi-currency: `amount` is in the bill's currency; `amount_sgd` is kept for an SGD one.
            'amount' => 'required_without:amount_sgd|nullable|numeric|gt:0',
            'amount_sgd' => 'required_without:amount|nullable|numeric|gt:0',
            'currency_code' => 'sometimes|nullable|string|size:3',
            'exchange_rate' => 'sometimes|nullable|numeric|gt:0',
            // GST is worked out from the tax code, as on a Sales Invoice
            // (Dennis, 2026-09-26) -- never keyed in.
            'tax_code' => 'sometimes|nullable|string|max:10',
            // The screen's "Expense account (optional -- defaults to 5000
            // Cost of services)". Until 2026-09-26 it was not read here,
            // so every bill posted to 5000 whatever was picked -- found by
            // the self-test (docs/self-test.md).
            'expense_account_id' => 'sometimes|nullable|uuid',
        ]);
        $expenseAccountId = $data['expense_account_id'] ?? null;
        if ($expenseAccountId !== null && ! Account::where('id', $expenseAccountId)->where('company_id', $user->company_id)
            ->where('account_type', Account::TYPE_EXPENSE)->where('is_active', true)->exists()) {
            throw new ApiException(422, 'The expense account must be one of this company\'s active expense accounts.');
        }
        $code = strtoupper($data['tax_code'] ?? TaxCode::DEFAULT_PURCHASE_CODE);
        $taxCode = TaxCode::where('company_id', $user->company_id)->where('code', $code)->where('is_active', true)->first();
        if ($taxCode === null || $taxCode->kind !== TaxCode::KIND_PURCHASE) {
            throw new ApiException(422, "{$code} is not an active purchase tax code. Pick one of the purchase codes under Maintenance -> Tax Types.");
        }
        $this->supplierOrFail($user->company_id, $data['supplier_id']);
        try {
            [$currency, $xrate] = Currency::resolve($user->company_id, $data['currency_code'] ?? null, $data['exchange_rate'] ?? null, $data['invoice_date'], $data['supplier_id']);
        } catch (CurrencyRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }
        if (! Currency::isBase($currency) && empty($data['amount'])) {
            throw new ApiException(422, "Key the amount in {$currency}.");
        }

        try {
            $bill = DB::transaction(function () use ($user, $data, $taxCode, $expenseAccountId, $currency, $xrate) {
                // GST worked out in the bill's currency, then each figure in
                // SGD at the bill's rate (the GST return reads the SGD).
                $netFx = Money::of($data['amount'] ?? $data['amount_sgd']);
                $rate = Money::of($taxCode->rate_percent);
                $gstFx = Tax::gstFor($netFx, $rate);
                $net = Currency::toSgd($netFx, $xrate);
                $gst = Currency::toSgd($gstFx, $xrate);

                $bill = SupplierInvoice::create([
                    'company_id' => $user->company_id,
                    'supplier_id' => $data['supplier_id'],
                    'purchase_order_id' => $data['purchase_order_id'] ?? null,
                    'expense_account_id' => $expenseAccountId,
                    'bill_number' => Numbering::next($user->company_id, 'supplier_invoice'),
                    'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
                    'invoice_date' => $data['invoice_date'],
                    'due_date' => PayablesService::dueDateForBill($data['supplier_id'], Carbon::parse($data['invoice_date']))?->toDateString(),
                    'description' => $data['description'],
                    'amount_sgd' => $net->toString(),
                    'tax_code' => $taxCode->code,
                    'gst_rate' => $rate->toString(),
                    'gst_amount_sgd' => $gst->toString(),
                    'total_amount_sgd' => $net->plus($gst)->toString(),
                    'currency_code' => $currency,
                    'exchange_rate' => $xrate,
                    'amount_fx' => $netFx->toString(),
                    'gst_amount_fx' => $gstFx->toString(),
                    'total_amount_fx' => $netFx->plus($gstFx)->toString(),
                    'amount_paid_fx' => '0.00',
                ]);

                PayablesService::matchBillToPo($bill, $user->id);

                Audit::record(
                    entityType: 'supplier_invoice',
                    entityId: $bill->id,
                    action: 'received',
                    actorUserId: $user->id,
                    details: "{$bill->bill_number}: {$bill->match_note}",
                    newValue: [
                        'bill_number' => $bill->bill_number,
                        'total_sgd' => (string) $bill->total_amount_sgd,
                        'match_status' => $bill->match_status,
                        'status' => $bill->status,
                    ],
                );

                return $bill;
            });
        } catch (PayablesRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->present($bill->fresh()));
    }

    /**
     * Match a bill that is still an exception against its purchase
     * order again -- for the bills flagged before open item 4.5 was
     * settled (a different amount alone no longer holds a bill back).
     * A bill still from the wrong supplier, or against an unapproved
     * PO, stays an exception.
     */
    public function rematch(Request $request, string $billId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');
        $bill = $this->billOrFail($user->company_id, $billId);
        if ($bill->status !== SupplierInvoice::STATUS_EXCEPTION) {
            throw new ApiException(409, "{$bill->bill_number} is not a matching exception, so there is nothing to match again.");
        }

        try {
            DB::transaction(function () use ($bill, $user) {
                $oldNote = $bill->match_note;
                PayablesService::matchBillToPo($bill, $user->id);
                Audit::record(
                    entityType: 'supplier_invoice',
                    entityId: $bill->id,
                    action: 'rematched',
                    actorUserId: $user->id,
                    details: "{$bill->bill_number}: {$bill->match_note}",
                    oldValue: ['status' => SupplierInvoice::STATUS_EXCEPTION, 'match_note' => $oldNote],
                    newValue: ['status' => $bill->status, 'match_status' => $bill->match_status],
                );
            });
        } catch (PayablesRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->present($bill->fresh()));
    }

    /** ACC-004: UNGL -- reverse this bill's GL posting. */
    public function ungl(Request $request, string $billId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $bill = $this->billOrFail($user->company_id, $billId);
        $data = $request->validate(['reason' => 'required|string|min:1']);

        try {
            $reversal = Posting::unpost(Posting::SOURCE_SUPPLIER_INVOICE, $bill->id, $user->id, $data['reason'], 'supplier_invoice');
        } catch (PostingError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json(['status' => 'reversed', 'reversal_voucher' => $reversal->voucher_number]);
    }
}
