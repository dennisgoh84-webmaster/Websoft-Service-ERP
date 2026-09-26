<?php

namespace App\Services;

use App\Exceptions\ARRuleViolation;
use App\Exceptions\PostingError;
use App\Models\CompanyIndividual;
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
        $paid = PaymentAllocation::where('invoice_id', $invoice->id)->get()
            ->reduce(fn (Money $carry, PaymentAllocation $a) => $carry->plus(Money::of($a->amount_sgd)), Money::of($invoice->pre_migration_paid_sgd ?? 0));
        $invoice->amount_paid_sgd = $paid->toString();

        $total = Money::of($invoice->total_amount_sgd);
        if ($paid->toFloat() <= 0) {
            $invoice->status = Invoice::STATUS_OUTSTANDING;
        } elseif ($paid->toFloat() >= $total->toFloat()) {
            $invoice->status = Invoice::STATUS_PAID;
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
        if ($amount->toFloat() > $payment->unallocatedSgd()->toFloat()) {
            throw new ARRuleViolation("Only SGD {$payment->unallocatedSgd()->toString()} of this payment is still unallocated.");
        }
        if ($amount->toFloat() > $invoice->outstandingSgd()->toFloat()) {
            throw new ARRuleViolation("Invoice {$invoice->invoice_number} only has SGD {$invoice->outstandingSgd()->toString()} outstanding.");
        }

        $allocation = PaymentAllocation::create([
            'company_id' => $payment->company_id,
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount_sgd' => $amount->toString(),
        ]);
        self::recalculateInvoiceStatus($invoice);
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
            ->where('status', '!=', Invoice::STATUS_PAID)
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
