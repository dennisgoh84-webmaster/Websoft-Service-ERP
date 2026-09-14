<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Exceptions\PayablesRuleViolation;
use App\Exceptions\PostingError;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\CompanyIndividual;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\Numbering;
use App\Services\PayablesService;
use App\Services\Posting;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Payment Vouchers (money paid out to a supplier). Mirrors the
 * payment-voucher half of backend/app/routers/payables.py -- see
 * App\Services\PayablesService for the allocation logic and
 * App\Services\Posting for the GL/Bank steps this orchestrates.
 *
 * Unlike an Invoice or a bill, a Payment Voucher's GL posting is not
 * optional: creating one fails outright if it can't be posted (ACC-001/003
 * -- Dr AP / Cr bank), exactly like the Python router. This is why
 * this module was deferred until GL posting + Bank existed -- see
 * PayablesService's class docblock's history.
 *
 * NOT yet converted: CSV/Excel/.docx export, "Email Payment Voucher".
 */
class SupplierPaymentController extends Controller
{
    private const MODULE = 'accounts_payable';

    private function supplierOrFail(string $companyId, string $supplierId): CompanyIndividual
    {
        $supplier = CompanyIndividual::find($supplierId);
        if (! $supplier || $supplier->company_id !== $companyId || ! $supplier->is_supplier) {
            throw new ApiException(404, "Supplier not found -- check it exists and is marked 'Is Supplier' on the Company/Individual page.");
        }

        return $supplier;
    }

    private function paymentOrFail(string $companyId, string $paymentId): SupplierPayment
    {
        $payment = SupplierPayment::with('allocations')->find($paymentId);
        if (! $payment || $payment->company_id !== $companyId) {
            throw new ApiException(404, 'Payment voucher not found');
        }

        return $payment;
    }

    private function billOrFail(string $companyId, string $billId): SupplierInvoice
    {
        $bill = SupplierInvoice::find($billId);
        if (! $bill || $bill->company_id !== $companyId) {
            throw new ApiException(404, 'Supplier invoice not found');
        }

        return $bill;
    }

    private function present(SupplierPayment $payment): array
    {
        $glEntry = Posting::liveEntryFor(Posting::SOURCE_SUPPLIER_PAYMENT, $payment->id);
        $bankTxn = Posting::liveBankTransactionFor(Posting::SOURCE_SUPPLIER_PAYMENT, $payment->id);

        return [
            'id' => $payment->id,
            'voucher_number' => $payment->voucher_number,
            'supplier_id' => $payment->supplier_id,
            'payment_date' => optional($payment->payment_date)->toDateString(),
            'amount_sgd' => (float) $payment->amount_sgd,
            'allocated_sgd' => $payment->allocatedSgd()->toFloat(),
            'unallocated_sgd' => $payment->unallocatedSgd()->toFloat(),
            'method' => $payment->method,
            'reference' => $payment->reference,
            'allocations' => $payment->allocations->map(fn ($a) => [
                'id' => $a->id, 'supplier_invoice_id' => $a->supplier_invoice_id, 'amount_sgd' => (float) $a->amount_sgd,
            ])->values(),
            'bank_account_id' => $payment->bank_account_id,
            'gl_status' => $glEntry ? 'posted' : 'not_posted',
            'gl_voucher_number' => $glEntry?->voucher_number,
            'bank_status' => $bankTxn ? 'banked' : null,
            'bank_transaction_number' => $bankTxn?->transaction_number,
        ];
    }

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $query = SupplierPayment::with('allocations')->where('company_id', $user->company_id);
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->query('supplier_id'));
        }

        return $query->orderByDesc('payment_date')->get()->map(fn (SupplierPayment $p) => $this->present($p))->values();
    }

    public function show(Request $request, string $paymentId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return response()->json($this->present($this->paymentOrFail($user->company_id, $paymentId)));
    }

    /**
     * Raise a Payment Voucher (PV). Like AR, allocation is a separate
     * manual decision -- money can be paid and allocated afterwards.
     */
    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $data = $request->validate([
            'supplier_id' => 'required|uuid',
            'payment_date' => 'required|date',
            'amount_sgd' => 'required|numeric|gt:0',
            'method' => 'sometimes|in:bank_transfer,paynow,cheque,cash,credit_card,other',
            'reference' => 'sometimes|nullable|string',
            'notes' => 'sometimes|nullable|string',
            'bank_account_id' => 'required|uuid',
            'allocations' => 'sometimes|array',
            'allocations.*.supplier_invoice_id' => 'required_with:allocations|uuid',
            'allocations.*.amount_sgd' => 'required_with:allocations|numeric|gt:0',
        ]);
        $supplier = $this->supplierOrFail($user->company_id, $data['supplier_id']);

        try {
            $payment = DB::transaction(function () use ($user, $data, $supplier) {
                $payment = SupplierPayment::create([
                    'company_id' => $user->company_id,
                    'supplier_id' => $data['supplier_id'],
                    'voucher_number' => Numbering::next($user->company_id, 'payment'),
                    'payment_date' => $data['payment_date'],
                    'amount_sgd' => $data['amount_sgd'],
                    'method' => $data['method'] ?? SupplierPayment::METHOD_BANK_TRANSFER,
                    'reference' => $data['reference'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'bank_account_id' => $data['bank_account_id'],
                    'paid_by_user_id' => $user->id,
                ]);

                // ACC-001/003: the payment is an accounting event --
                // post it now (Dr AP / Cr bank). The explicit Bank
                // step (ACC-002) is separate.
                try {
                    Posting::postSupplierPayment($payment, $user->id);
                } catch (PostingError $e) {
                    throw new PayablesRuleViolation($e->getMessage());
                }

                foreach ($data['allocations'] ?? [] as $entry) {
                    $bill = $this->billOrFail($user->company_id, $entry['supplier_invoice_id']);
                    PayablesService::allocateSupplierPayment($payment, $bill, Money::of($entry['amount_sgd']));
                }

                Audit::record(
                    entityType: 'supplier_payment',
                    entityId: $payment->id,
                    action: 'recorded',
                    actorUserId: $user->id,
                    details: "{$payment->voucher_number}: SGD {$data['amount_sgd']} to {$supplier->name}",
                    newValue: ['voucher_number' => $payment->voucher_number, 'amount_sgd' => (string) $data['amount_sgd'], 'supplier' => $supplier->name],
                );

                return $payment;
            });
        } catch (PayablesRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->present($payment->fresh('allocations')));
    }

    public function allocate(Request $request, string $paymentId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $payment = $this->paymentOrFail($user->company_id, $paymentId);
        $data = $request->validate([
            'allocations' => 'required|array|min:1',
            'allocations.*.supplier_invoice_id' => 'required|uuid',
            'allocations.*.amount_sgd' => 'required|numeric|gt:0',
        ]);

        try {
            $applied = DB::transaction(function () use ($user, $payment, $data) {
                $applied = [];
                foreach ($data['allocations'] as $entry) {
                    $bill = $this->billOrFail($user->company_id, $entry['supplier_invoice_id']);
                    PayablesService::allocateSupplierPayment($payment, $bill, Money::of($entry['amount_sgd']));
                    $applied[] = "{$bill->bill_number}={$entry['amount_sgd']}";
                }

                Audit::record(
                    entityType: 'supplier_payment',
                    entityId: $payment->id,
                    action: 'allocated',
                    actorUserId: $user->id,
                    details: implode(', ', $applied),
                    newValue: ['allocated_to' => $applied],
                );

                return $applied;
            });
        } catch (PayablesRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->present($payment->fresh('allocations')));
    }

    /** ACC-002: explicit Bank step -- Finance confirms the money actually left the account. */
    public function bank(Request $request, string $paymentId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $payment = $this->paymentOrFail($user->company_id, $paymentId);

        try {
            $txn = Posting::bankSupplierPayment($payment, $user->id);
        } catch (PostingError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json(['status' => 'banked', 'transaction_number' => $txn->transaction_number]);
    }

    /** ACC-004: void the bank-book line -- a mistake, never deleted. */
    public function unbank(Request $request, string $paymentId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $payment = $this->paymentOrFail($user->company_id, $paymentId);
        $data = $request->validate(['reason' => 'required|string|min:1']);

        try {
            Posting::unbank(Posting::SOURCE_SUPPLIER_PAYMENT, $payment->id, 'payment_voucher', $user->id, $data['reason'], 'supplier_payment');
        } catch (PostingError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json(['status' => 'unbanked']);
    }

    /** ACC-004: UNGL -- reverse this voucher's GL posting. */
    public function ungl(Request $request, string $paymentId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $payment = $this->paymentOrFail($user->company_id, $paymentId);
        $data = $request->validate(['reason' => 'required|string|min:1']);

        try {
            $reversal = Posting::unpost(Posting::SOURCE_SUPPLIER_PAYMENT, $payment->id, $user->id, $data['reason'], 'supplier_payment');
        } catch (PostingError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json(['status' => 'reversed', 'reversal_voucher' => $reversal->voucher_number]);
    }
}
