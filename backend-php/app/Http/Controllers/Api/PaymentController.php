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
use App\Models\Payment;
use App\Services\AccountsReceivableService;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\DocxForms;
use App\Services\Numbering;
use App\Services\Posting;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Receipt Vouchers (money received from a customer, AR-001). Mirrors
 * the payment half of backend/app/routers/accounts_receivable.py --
 * see App\Services\AccountsReceivableService for the allocation logic
 * and App\Services\Posting for the GL/Bank steps this orchestrates.
 *
 * Like a Payment Voucher, a receipt's GL posting is not optional:
 * recording one fails outright if it can't be posted (ACC-001/003 --
 * Dr bank / Cr AR).
 *
 * NOT yet converted: CSV/Excel export. (The .docx export and "Email
 * Receipt" endpoints WERE the other gap here; both are converted now
 * -- see exportDocx()/email() below. The Customer Statement endpoints
 * are converted too, on AccountsReceivableController.)
 */
class PaymentController extends Controller
{
    use SendsDocuments;

    private const MODULE = 'accounts_receivable';

    private function customerOrFail(string $companyId, string $customerId): CompanyIndividual
    {
        $customer = CompanyIndividual::find($customerId);
        if (! $customer || $customer->company_id !== $companyId) {
            throw new ApiException(404, 'Company / Individual not found');
        }

        return $customer;
    }

    private function paymentOrFail(string $companyId, string $paymentId): Payment
    {
        $payment = Payment::with('allocations')->find($paymentId);
        if (! $payment || $payment->company_id !== $companyId) {
            throw new ApiException(404, 'Payment not found');
        }

        return $payment;
    }

    private function invoiceOrFail(string $companyId, string $invoiceId): Invoice
    {
        $invoice = Invoice::find($invoiceId);
        if (! $invoice || $invoice->company_id !== $companyId) {
            throw new ApiException(404, 'Invoice not found');
        }

        return $invoice;
    }

    private function present(Payment $payment): array
    {
        $glEntry = Posting::liveEntryFor(Posting::SOURCE_RECEIPT, $payment->id);
        $bankTxn = Posting::liveBankTransactionFor(Posting::SOURCE_RECEIPT, $payment->id);
        $invoiceNumbers = Invoice::whereIn('id', $payment->allocations->pluck('invoice_id'))->pluck('invoice_number', 'id');

        return [
            'id' => $payment->id,
            'voucher_number' => $payment->voucher_number,
            'customer_id' => $payment->customer_id,
            'payment_date' => optional($payment->payment_date)->toDateString(),
            'amount_sgd' => (float) $payment->amount_sgd,
            'allocated_sgd' => $payment->allocatedSgd()->toFloat(),
            'unallocated_sgd' => $payment->unallocatedSgd()->toFloat(),
            'method' => $payment->method,
            'reference' => $payment->reference,
            'notes' => $payment->notes,
            'allocations' => $payment->allocations->map(fn ($a) => [
                'id' => $a->id, 'invoice_id' => $a->invoice_id,
                'invoice_number' => $invoiceNumbers->get($a->invoice_id),
                'amount_sgd' => (float) $a->amount_sgd,
            ])->values(),
            'bank_account_id' => $payment->bank_account_id,
            'gl_status' => $glEntry ? 'posted' : 'not_posted',
            'gl_voucher_number' => $glEntry?->voucher_number,
            'bank_status' => $bankTxn ? 'banked' : 'not_banked',
            'bank_transaction_number' => $bankTxn?->transaction_number,
        ];
    }

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $query = Payment::with('allocations')->where('company_id', $user->company_id);
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }
        $payments = $query->orderByDesc('payment_date')->orderByDesc('created_at')->get();
        if ($request->boolean('unallocated_only')) {
            $payments = $payments->filter(fn (Payment $p) => $p->unallocatedSgd()->toFloat() > 0)->values();
        }

        return $payments->map(fn (Payment $p) => $this->present($p))->values();
    }

    public function show(Request $request, string $paymentId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return response()->json($this->present($this->paymentOrFail($user->company_id, $paymentId)));
    }

    /**
     * Record money received. Allocation is optional here -- AR-001
     * makes it a manual decision, so a receipt can sit unallocated on
     * the customer's account until Finance decides what it settles.
     */
    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $data = $request->validate([
            'customer_id' => 'required|uuid',
            'payment_date' => 'required|date',
            'amount_sgd' => 'required|numeric|gt:0',
            'method' => 'sometimes|in:bank_transfer,paynow,cheque,cash,credit_card,other',
            'reference' => 'sometimes|nullable|string',
            'notes' => 'sometimes|nullable|string',
            'bank_account_id' => 'required|uuid',
            'allocations' => 'sometimes|array',
            'allocations.*.invoice_id' => 'required_with:allocations|uuid',
            'allocations.*.amount_sgd' => 'required_with:allocations|numeric|gt:0',
        ]);
        $customer = $this->customerOrFail($user->company_id, $data['customer_id']);

        try {
            $payment = DB::transaction(function () use ($user, $data, $customer) {
                $payment = Payment::create([
                    'company_id' => $user->company_id,
                    'customer_id' => $data['customer_id'],
                    'voucher_number' => Numbering::next($user->company_id, 'receipt'),
                    'payment_date' => $data['payment_date'],
                    'amount_sgd' => $data['amount_sgd'],
                    'method' => $data['method'] ?? Payment::METHOD_BANK_TRANSFER,
                    'reference' => $data['reference'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'bank_account_id' => $data['bank_account_id'],
                    'recorded_by_user_id' => $user->id,
                ]);

                // ACC-001/003: the receipt is an accounting event --
                // post it now (Dr bank / Cr AR). The explicit Bank
                // step (ACC-002) is separate.
                try {
                    Posting::postReceipt($payment, $user->id);
                } catch (PostingError $e) {
                    throw new ARRuleViolation($e->getMessage());
                }

                foreach ($data['allocations'] ?? [] as $entry) {
                    $invoice = $this->invoiceOrFail($user->company_id, $entry['invoice_id']);
                    AccountsReceivableService::allocatePayment($payment, $invoice, Money::of($entry['amount_sgd']));
                }

                Audit::record(
                    entityType: 'payment',
                    entityId: $payment->id,
                    action: 'recorded',
                    actorUserId: $user->id,
                    details: "{$payment->voucher_number}: SGD {$data['amount_sgd']} from {$customer->name} ({$payment->method}, ref=".($data['reference'] ?? '-').')',
                    newValue: ['amount_sgd' => (string) $data['amount_sgd'], 'customer' => $customer->name, 'allocations' => count($data['allocations'] ?? [])],
                );

                return $payment;
            });
        } catch (ARRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->present($payment->fresh('allocations')));
    }

    /** AR-001: Finance says which invoices this receipt settles. */
    public function allocate(Request $request, string $paymentId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $payment = $this->paymentOrFail($user->company_id, $paymentId);
        $data = $request->validate([
            'allocations' => 'required|array|min:1',
            'allocations.*.invoice_id' => 'required|uuid',
            'allocations.*.amount_sgd' => 'required|numeric|gt:0',
        ]);

        try {
            $applied = DB::transaction(function () use ($user, $payment, $data) {
                $applied = [];
                foreach ($data['allocations'] as $entry) {
                    $invoice = $this->invoiceOrFail($user->company_id, $entry['invoice_id']);
                    AccountsReceivableService::allocatePayment($payment, $invoice, Money::of($entry['amount_sgd']));
                    $applied[] = "{$invoice->invoice_number}={$entry['amount_sgd']}";
                }

                Audit::record(
                    entityType: 'payment',
                    entityId: $payment->id,
                    action: 'allocated',
                    actorUserId: $user->id,
                    details: implode(', ', $applied),
                    newValue: ['allocated_to' => $applied],
                );

                return $applied;
            });
        } catch (ARRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->present($payment->fresh('allocations')));
    }

    /** ACC-002: Finance confirms the money reached the bank -- writes the receipt into the bank book as one debit line. */
    public function bank(Request $request, string $paymentId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $payment = $this->paymentOrFail($user->company_id, $paymentId);

        try {
            $txn = Posting::bankReceipt($payment, $user->id);
        } catch (PostingError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json(['status' => 'banked', 'transaction_number' => $txn->transaction_number]);
    }

    public function unbank(Request $request, string $paymentId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $payment = $this->paymentOrFail($user->company_id, $paymentId);
        $data = $request->validate(['reason' => 'required|string|min:1']);

        try {
            $txn = Posting::unbank(Posting::SOURCE_RECEIPT, $payment->id, 'receipt_voucher', $user->id, $data['reason'], 'payment');
        } catch (PostingError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json(['status' => 'unbanked', 'transaction_number' => $txn->transaction_number]);
    }

    /** ACC-004: UNGL -- reverse this receipt's GL posting. */
    public function ungl(Request $request, string $paymentId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $payment = $this->paymentOrFail($user->company_id, $paymentId);
        $data = $request->validate(['reason' => 'required|string|min:1']);

        try {
            $reversal = Posting::unpost(Posting::SOURCE_RECEIPT, $payment->id, $user->id, $data['reason'], 'payment');
        } catch (PostingError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json(['status' => 'reversed', 'reversal_voucher' => $reversal->voucher_number]);
    }

    /**
     * Every invoice number in the company, keyed by id -- mirrors the
     * Python router's `_invoice_numbers()` helper, which the Receipt
     * form uses to label each allocation line.
     *
     * @return array<string, string>
     */
    private function invoiceNumbers(string $companyId): array
    {
        return Invoice::where('company_id', $companyId)->pluck('invoice_number', 'id')->all();
    }

    /** GET /accounts-receivable/payments/{id}/export.docx -- the Word button on the Receipt Voucher page. */
    public function exportDocx(Request $request, string $paymentId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $payment = $this->paymentOrFail($user->company_id, $paymentId);
        $customer = CompanyIndividual::find($payment->customer_id);
        $company = Company::find($user->company_id);

        return $this->docxResponse(
            DocxForms::receiptToDocx($payment, $customer, $company, $this->invoiceNumbers($user->company_id)),
            "{$payment->voucher_number}.docx",
        );
    }

    /**
     * Email Receipt Voucher (2026-09-12) -- same real-send pattern as
     * Purchase Order's Email button.
     */
    public function email(Request $request, string $paymentId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $payment = $this->paymentOrFail($user->company_id, $paymentId);
        $customer = CompanyIndividual::find($payment->customer_id);
        if (! $customer || ! $customer->billing_email) {
            throw new ApiException(422, 'This customer has no email on file -- add one on the Company/Individual page first.');
        }
        $company = Company::find($user->company_id);
        $companyName = $this->companyName($company);
        $docxBytes = DocxForms::receiptToDocx($payment, $customer, $company, $this->invoiceNumbers($user->company_id));
        $amount = number_format((float) $payment->amount_sgd, 2, '.', '');
        $body = "Dear {$customer->name},\n\n"
            ."Please find attached Receipt {$payment->voucher_number} dated "
            .$payment->payment_date->toDateString()." for SGD {$amount}.\n\n"
            ."Regards,\n{$companyName}";

        $result = $this->emailDocument(
            $company,
            $customer->billing_email,
            "Receipt {$payment->voucher_number} - {$companyName}",
            $body,
            $docxBytes,
            $payment->voucher_number,
        );

        Audit::record(
            entityType: 'payment',
            entityId: $payment->id,
            action: 'emailed',
            actorUserId: $user->id,
            details: "{$payment->voucher_number} emailed to {$customer->billing_email}",
        );

        return response()->json($result);
    }
}
