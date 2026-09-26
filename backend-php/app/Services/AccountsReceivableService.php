<?php

namespace App\Services;

use App\Exceptions\ARRuleViolation;
use App\Exceptions\PostingError;
use App\Models\CompanyIndividual;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Accounts Receivable business logic. Mirrors
 * backend/app/services/accounts_receivable.py -- see that file's
 * docstring for AR-001/002/003.
 */
class AccountsReceivableService
{
    /**
     * Aging buckets, by days past due: [name, lowerDays, upperDays].
     * upperDays null means unbounded (over_90).
     */
    private const AGING_BUCKETS = [
        ['current', null, 0],
        ['1_30', 1, 30],
        ['31_60', 31, 60],
        ['61_90', 61, 90],
        ['over_90', 91, null],
    ];

    /**
     * Which aging bucket an outstanding invoice falls into. An invoice
     * with no due date (customer terms not agreed) is treated as
     * current rather than overdue -- it cannot be late against a date
     * that was never set.
     */
    public static function agingBucketFor(?Carbon $dueDate, Carbon $asAt): string
    {
        if ($dueDate === null || $dueDate->greaterThanOrEqualTo($asAt)) {
            return 'current';
        }
        $daysOverdue = $dueDate->diffInDays($asAt);
        foreach (self::AGING_BUCKETS as [$name, $lower, $upper]) {
            if ($lower === null) {
                continue;
            }
            if ($daysOverdue >= $lower && ($upper === null || $daysOverdue <= $upper)) {
                return $name;
            }
        }

        return 'over_90';
    }

    /**
     * AR-002: the owner and Finance write off, with no amount limit
     * (Dennis, 2026-09-26: the write-off approval amount "can totally
     * remove", and Finance "should be able to write off without" the
     * owner). Everyone else is refused. A reason is always required.
     */
    public static function canWriteOff(User $actor): bool
    {
        return in_array($actor->role, [User::ROLE_OWNER, User::ROLE_FINANCE], true);
    }

    /**
     * AR-002: write off the outstanding balance as bad debt. The
     * invoice is never deleted or altered beyond this -- it is marked
     * written off and keeps its full history, per the rule that
     * financial records are never destroyed.
     */
    public static function writeOffInvoice(Invoice $invoice, User $actor, string $reason): Invoice
    {
        if ($invoice->status === Invoice::STATUS_WRITTEN_OFF) {
            throw new ARRuleViolation('That invoice has already been written off.');
        }
        if ($invoice->status === Invoice::STATUS_PAID) {
            throw new ARRuleViolation('That invoice is fully paid -- nothing to write off.');
        }
        if (trim($reason) === '') {
            throw new ARRuleViolation('A reason is required for every write-off (AR-002).');
        }

        $outstanding = $invoice->outstandingSgd();
        if (! self::canWriteOff($actor)) {
            throw new ARRuleViolation('Only the owner or Finance can write off an invoice (AR-002).');
        }

        // The bad debt reaches the General Ledger as an expense (Dennis,
        // 2026-09-26): Dr 6700 / Cr 1100 for what was still owed. Both or
        // neither: an invoice is never left written off without its entry.
        DB::transaction(function () use ($invoice, $outstanding, $actor, $reason) {
            $invoice->status = Invoice::STATUS_WRITTEN_OFF;
            $invoice->save();
            try {
                Posting::postWriteOff($invoice, $outstanding, $actor->id, trim($reason));
            } catch (PostingError $e) {
                throw new ARRuleViolation($e->getMessage());
            }
        });

        return $invoice;
    }

    /**
     * Derive an invoice's paid state from what is allocated to it, so
     * it can't drift out of step with the payments actually applied.
     */
    public static function recalculateInvoiceStatus(Invoice $invoice): void
    {
        if ($invoice->status === Invoice::STATUS_WRITTEN_OFF) {
            return;
        }
        // Starts from what the old system had already settled on a
        // migrated invoice (zero on every invoice raised here), which
        // has no allocation rows behind it -- see docs/data-migration.md.
        // Paid in SGD is what each allocation cleared at the INVOICE's rate
        // (multi-currency: the receipt's own SGD value can differ -- that
        // difference is the realised exchange gain or loss); paid in the
        // invoice's currency is what was allocated.
        $allocations = PaymentAllocation::where('invoice_id', $invoice->id)->get();
        $pre = Money::of($invoice->pre_migration_paid_sgd ?? 0);
        $paid = $allocations->reduce(fn (Money $carry, PaymentAllocation $a) => $carry->plus(Money::of($a->invoice_amount_sgd ?? $a->amount_sgd)), $pre);
        $paidFx = $allocations->reduce(fn (Money $carry, PaymentAllocation $a) => $carry->plus(Money::of($a->amount_fx ?? $a->amount_sgd)), $pre);
        $invoice->amount_paid_sgd = $paid->toString();
        $invoice->amount_paid_fx = $paidFx->toString();
        // Issued credit notes take their total off too (BILL-003).
        $notes = CreditNote::where('invoice_id', $invoice->id)->where('status', CreditNote::STATUS_ISSUED)->get();
        $credited = $notes->reduce(fn (Money $carry, CreditNote $c) => $carry->plus(Money::of($c->total_amount_sgd)), Money::of(0));
        $creditedFx = $notes->reduce(fn (Money $carry, CreditNote $c) => $carry->plus($c->fx('total_amount')), Money::of(0));
        $invoice->credited_sgd = $credited->toString();
        $invoice->credited_fx = $creditedFx->toString();

        // Settled or not is judged in the invoice's own currency.
        $total = $invoice->fx('total_amount');
        $paid = $paidFx;
        $credited = $creditedFx;
        $settled = $paid->plus($credited);
        if ($settled->toFloat() <= 0) {
            $invoice->status = Invoice::STATUS_OUTSTANDING;
        } elseif ($settled->toFloat() >= $total->toFloat()) {
            // Fully credited with nothing paid reads "credited", not "paid".
            $invoice->status = $paid->toFloat() > 0 ? Invoice::STATUS_PAID : Invoice::STATUS_CREDITED;
        } else {
            $invoice->status = Invoice::STATUS_PARTIALLY_PAID;
        }
        $invoice->save();
    }

    /** Apply part (or all) of a payment to one invoice -- AR-001, always a manual decision by Finance. */
    public static function allocatePayment(Payment $payment, Invoice $invoice, Money $amount): PaymentAllocation
    {
        if ($amount->toFloat() <= 0) {
            throw new ARRuleViolation('Allocation amount must be greater than zero.');
        }
        if ($invoice->company_id !== $payment->company_id) {
            throw new ARRuleViolation('Invoice and payment belong to different companies.');
        }
        if ($invoice->customer_id !== $payment->customer_id) {
            throw new ARRuleViolation('That invoice belongs to a different customer than this payment.');
        }
        if ($invoice->status === Invoice::STATUS_WRITTEN_OFF) {
            throw new ARRuleViolation('That invoice has been written off.');
        }
        // Multi-currency: the amount is in the documents' own currency, and
        // a receipt settles invoices in its own currency only.
        $code = $invoice->currencyCode();
        if ($payment->currencyCode() !== $code) {
            throw new ARRuleViolation("{$payment->voucher_number} is in {$payment->currencyCode()} but invoice {$invoice->invoice_number} is in {$code} -- a receipt settles invoices in its own currency.");
        }
        $paymentLeft = $payment->unallocatedFx();
        $invoiceLeft = $invoice->outstandingFx();
        if ($amount->toFloat() > $paymentLeft->toFloat()) {
            throw new ARRuleViolation("Only {$code} {$paymentLeft->toString()} of this payment is still unallocated.");
        }
        if ($amount->toFloat() > $invoiceLeft->toFloat()) {
            throw new ARRuleViolation("Invoice {$invoice->invoice_number} only has {$code} {$invoiceLeft->toString()} outstanding.");
        }

        // Each side in SGD at its own document's rate -- the last of a
        // document takes exactly what it has left, so no cent is stranded.
        $paymentSgd = $amount->toString() === $paymentLeft->toString() ? $payment->unallocatedSgd() : Currency::toSgd($amount, $payment->rate());
        $invoiceSgd = $amount->toString() === $invoiceLeft->toString() ? $invoice->outstandingSgd() : Currency::toSgd($amount, $invoice->rate());
        $difference = $paymentSgd->minus($invoiceSgd); // + gain, - loss

        $allocation = PaymentAllocation::create([
            'company_id' => $payment->company_id,
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount_sgd' => $paymentSgd->toString(),
            'amount_fx' => $amount->toString(),
            'invoice_amount_sgd' => $invoiceSgd->toString(),
            'fx_difference_sgd' => $difference->toString(),
        ]);
        self::recalculateInvoiceStatus($invoice);
        if ($difference->toString() !== '0.00') {
            Posting::postExchangeDifference(
                companyId: $payment->company_id, receivable: true, allocationId: $allocation->id,
                voucherNumber: "{$payment->voucher_number}-FX-{$invoice->invoice_number}", on: Carbon::parse($payment->payment_date),
                difference: $difference, narration: "Exchange difference, receipt {$payment->voucher_number} on invoice {$invoice->invoice_number} ({$code})",
                actorUserId: null,
            );
        }
        // unallocatedSgd()/allocatedSgd() reduce over the cached
        // `allocations` relation -- refresh it so a second allocation
        // against the same $payment instance (e.g. several lines in
        // one request) sees this one, not a stale empty/partial
        // collection.
        $payment->load('allocations');

        return $allocation;
    }

    /**
     * @return array{0: Carbon, 1: array<int, array{customer_id: string, customer_name: string, current: float, days_1_30: float, days_31_60: float, days_61_90: float, over_90: float, total: float}>}
     */
    public static function agingRows(string $companyId, ?Carbon $asAt = null): array
    {
        $asAt = $asAt ?? Carbon::today();
        $invoices = Invoice::where('company_id', $companyId)
            ->whereNotIn('status', [Invoice::STATUS_PAID, Invoice::STATUS_WRITTEN_OFF])
            ->get();
        $customerNames = CompanyIndividual::where('company_id', $companyId)->pluck('name', 'id');

        $buckets = [];
        foreach ($invoices as $invoice) {
            $outstanding = $invoice->outstandingSgd();
            if ($outstanding->toFloat() <= 0) {
                continue;
            }
            $customerId = $invoice->customer_id;
            if (! isset($buckets[$customerId])) {
                $buckets[$customerId] = ['current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 0.0];
            }
            $bucket = self::agingBucketFor($invoice->due_date, $asAt);
            $buckets[$customerId][$bucket] += $outstanding->toFloat();
        }

        $rows = [];
        foreach ($buckets as $customerId => $b) {
            $rows[] = [
                'customer_id' => $customerId,
                'customer_name' => $customerNames->get($customerId, '(unknown)'),
                'current' => $b['current'],
                'days_1_30' => $b['1_30'],
                'days_31_60' => $b['31_60'],
                'days_61_90' => $b['61_90'],
                'over_90' => $b['over_90'],
                'total' => array_sum($b),
            ];
        }
        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);

        return [$asAt, $rows];
    }

    /**
     * Everything this customer currently owes, plus any receipt money
     * still sitting unallocated on their account. Mirrors
     * backend/app/routers/accounts_receivable.py's
     * `_build_customer_statement`, and lives here for the same reason
     * that helper is shared in Python: the JSON endpoint, the .docx
     * export and the Email attachment must all show the same figures --
     * "export what's on screen" always matches (2026-09-12).
     *
     * Returns the CompanyIndividualStatement shape from
     * app/schemas/schemas.py, field for field.
     *
     * @return array<string, mixed>
     */
    public static function buildCustomerStatement(CompanyIndividual $customer, string $companyId, ?Carbon $asAt = null): array
    {
        $asAt = $asAt ?? Carbon::today();

        $invoices = Invoice::where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            // Paid, or credited in full (BILL-003): nothing left to show.
            ->whereNotIn('status', [Invoice::STATUS_PAID, Invoice::STATUS_CREDITED])
            ->orderBy('issued_at')
            ->get();

        $lines = [];
        $totalOutstanding = Money::of(0);
        foreach ($invoices as $invoice) {
            $outstanding = $invoice->outstandingSgd();
            $lines[] = [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'description' => $invoice->description,
                'issued_on' => optional($invoice->issued_at)->toDateString(),
                'due_date' => optional($invoice->due_date)->toDateString(),
                'total_amount_sgd' => (float) $invoice->total_amount_sgd,
                'amount_paid_sgd' => (float) $invoice->amount_paid_sgd,
                'credited_sgd' => (float) $invoice->credited_sgd,
                'outstanding_sgd' => $outstanding->toFloat(),
                'status' => $invoice->status,
                'is_disputed' => (bool) $invoice->is_disputed,
                // Python: max((as_at - due_date).days, 0), and 0 when the
                // invoice has no due date -- never an invented lateness.
                'days_overdue' => $invoice->due_date
                    ? max((int) $invoice->due_date->copy()->startOfDay()->diffInDays($asAt->copy()->startOfDay(), false), 0)
                    : 0,
            ];
            $totalOutstanding = $totalOutstanding->plus($outstanding);
        }

        $unallocated = Money::of(0);
        foreach (Payment::with('allocations')->where('company_id', $companyId)->where('customer_id', $customer->id)->get() as $payment) {
            $unallocated = $unallocated->plus($payment->unallocatedSgd());
        }

        return [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'as_at' => $asAt->toDateString(),
            'payment_terms_days' => $customer->payment_terms_days,
            'lines' => $lines,
            'total_outstanding_sgd' => $totalOutstanding->toFloat(),
            'unallocated_credit_sgd' => $unallocated->toFloat(),
        ];
    }
}
