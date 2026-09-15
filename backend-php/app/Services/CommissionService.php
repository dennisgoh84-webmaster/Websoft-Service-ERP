<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\CommissionPayout;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Commission Management -- payout generation, approval, clawback and
 * payment. Mirrors backend/app/services/commissions.py
 * (docs/open-business-decisions.md 6.3 approval, 6.4 clawback, 6.5
 * payout).
 *
 * Built on the same calculation the Commission report uses
 * (App\Services\ReportsService::commissionRows): the report says what
 * commission *would be*, this turns it into discrete, approvable,
 * payable records. The arithmetic is shared rather than duplicated --
 * `commissionFor()` below is what both call -- so a payout can never
 * disagree with the report it came from.
 *
 * TWO DEVIATIONS FROM PYTHON, both deliberate:
 *
 * 1. Python allocates the payout number with
 *    `next_document_number(db, company_id, "CP")` -- three positional
 *    arguments against a signature whose parameters after `db` are
 *    KEYWORD-ONLY (`def next_document_number(db, *, company_id,
 *    doc_kind, on=None)`). That raises TypeError before any number is
 *    allocated, so `generate_payouts` and `create_clawback` cannot
 *    currently run in `backend/` at all -- and because AR write-off
 *    calls `create_clawback`, writing off an invoice would fail too
 *    the moment a non-zero commission rate is set (a zero rate returns
 *    early, which is why nobody has hit it). Recorded in
 *    docs/php-conversion-plan.md as a `backend/` bug worth raising.
 *    Here the call goes through App\Services\Numbering properly.
 *
 * 2. That same call passes "CP" -- a PREFIX -- where the parameter is
 *    a document KIND, which would store `doc_kind = "CP"` in
 *    `document_sequences` and read as "CP" only by way of the
 *    3-letter fallback. Here the kind is `commission_payout` with an
 *    explicit "CP" prefix registered in Numbering::PREFIXES, so
 *    Document Control can customise the format like every other
 *    document.
 */
class CommissionService
{
    /** The commission rate, zero until an administrator sets one. */
    public static function ratePercent(string $companyId): string
    {
        return ReportsService::commissionRatePercent($companyId);
    }

    /**
     * The commission earned on one receipt allocation.
     *
     * The allocation is against the invoice TOTAL, which includes GST,
     * so it is first converted to its share of the invoice's NET
     * revenue -- GST never inflates commission. Zero when the invoice
     * has no total to divide by.
     */
    public static function commissionFor(PaymentAllocation $allocation, Invoice $invoice, Money $rate): Money
    {
        $total = Money::of($invoice->total_amount_sgd ?? 0);
        if ($total->toFloat() == 0.0) {
            return Money::of(0);
        }
        $revenue = Money::of($invoice->amount_sgd ?? 0);
        $netShare = Money::of($allocation->amount_sgd)->multipliedByMoney($revenue)->dividedBy($total->toString());
        $gp = $revenue->minus(Money::of($invoice->cost_sgd ?? 0));
        $gpRatio = $revenue->toFloat() == 0.0
            ? Money::of(0)
            : $gp->dividedBy($revenue->toString());

        return $netShare->multipliedByMoney($gpRatio)->multipliedByMoney($rate)->dividedBy(100)->quantize();
    }

    /** @return array{0: Carbon, 1: Carbon} The first and last day of a "YYYY-MM". */
    public static function monthRange(string $periodMonth): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $periodMonth.'-01')->startOfDay();

        return [$start, $start->copy()->endOfMonth()->startOfDay()];
    }

    /**
     * Generate DRAFT payouts for one month, one per salesperson.
     *
     * Refuses to run twice for the same month: regenerating would
     * silently double what is owed, so an existing (non-cancelled)
     * batch has to be cancelled first, which leaves the cancellation
     * on record.
     *
     * @return Collection<int, CommissionPayout>
     */
    public static function generatePayouts(string $companyId, string $periodMonth, string $createdByUserId): Collection
    {
        $exists = CommissionPayout::where('company_id', $companyId)
            ->where('period_month', $periodMonth)
            ->where('payout_type', CommissionPayout::TYPE_EARNING)
            ->where('status', '!=', CommissionPayout::STATUS_CANCELLED)
            ->exists();
        if ($exists) {
            throw new ApiException(422, "Commission payouts for {$periodMonth} already exist. "
                .'Cancel them first if you need to regenerate.');
        }

        $rate = Money::of(self::ratePercent($companyId));
        [$periodStart, $periodEnd] = self::monthRange($periodMonth);

        $totals = [];
        $allocations = PaymentAllocation::with(['payment', 'invoice'])->where('company_id', $companyId)->get();
        foreach ($allocations as $allocation) {
            $payment = $allocation->payment;
            $invoice = $allocation->invoice;
            if ($payment === null || $invoice === null) {
                continue;
            }
            if ($payment->payment_date->lt($periodStart) || $payment->payment_date->gt($periodEnd)) {
                continue;
            }
            $contract = $invoice->contract_id !== null ? Contract::find($invoice->contract_id) : null;
            $key = $contract?->sales_staff_id ?? '';
            $commission = self::commissionFor($allocation, $invoice, $rate);
            $totals[$key] = isset($totals[$key]) ? $totals[$key]->plus($commission) : $commission;
        }

        ksort($totals);
        $payouts = collect();
        foreach ($totals as $staffId => $amount) {
            if ($amount->toFloat() == 0.0) {
                continue;
            }
            $payouts->push(CommissionPayout::create([
                'company_id' => $companyId,
                'payout_number' => Numbering::next($companyId, 'commission_payout'),
                'payout_type' => CommissionPayout::TYPE_EARNING,
                'status' => CommissionPayout::STATUS_DRAFT,
                // An invoice whose contract names no salesperson still
                // earned commission; Python falls back to whoever
                // generated the batch rather than dropping the money,
                // and that is kept -- the row is visible and can be
                // cancelled, where a silent drop could not be noticed.
                'sales_staff_id' => $staffId === '' ? $createdByUserId : $staffId,
                'period_month' => $periodMonth,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'amount_sgd' => $amount->toString(),
                'rate_percent' => $rate->toString(),
                'submitted_by_user_id' => $createdByUserId,
            ]));
        }

        return $payouts;
    }

    public static function payoutOrFail(string $payoutId, string $companyId): CommissionPayout
    {
        $payout = CommissionPayout::find($payoutId);
        if (! $payout || $payout->company_id !== $companyId) {
            throw new ApiException(404, 'Commission payout not found.');
        }

        return $payout;
    }

    public static function submit(CommissionPayout $payout, string $userId): CommissionPayout
    {
        self::requireStatus($payout, CommissionPayout::STATUS_DRAFT, 'submitted');
        $payout->status = CommissionPayout::STATUS_PENDING_APPROVAL;
        $payout->submitted_by_user_id = $userId;
        $payout->submitted_at = Carbon::now();
        $payout->save();

        return $payout;
    }

    public static function approve(CommissionPayout $payout, string $userId): CommissionPayout
    {
        self::requireStatus($payout, CommissionPayout::STATUS_PENDING_APPROVAL, 'approved');
        $payout->status = CommissionPayout::STATUS_APPROVED;
        $payout->approved_by_user_id = $userId;
        $payout->approved_at = Carbon::now();
        $payout->save();

        return $payout;
    }

    public static function reject(CommissionPayout $payout, ?string $reason): CommissionPayout
    {
        self::requireStatus($payout, CommissionPayout::STATUS_PENDING_APPROVAL, 'rejected');
        $payout->status = CommissionPayout::STATUS_DRAFT;
        if ($reason !== null && $reason !== '') {
            $payout->notes = ($payout->notes ?? '')."\nRejected: {$reason}";
        }
        $payout->save();

        return $payout;
    }

    public static function markPaid(
        CommissionPayout $payout,
        string $userId,
        string $paidDate,
        ?string $paidReference,
    ): CommissionPayout {
        self::requireStatus($payout, CommissionPayout::STATUS_APPROVED, 'marked as paid');
        $payout->status = CommissionPayout::STATUS_PAID;
        $payout->paid_date = $paidDate;
        $payout->paid_reference = $paidReference;
        $payout->paid_by_user_id = $userId;
        $payout->save();

        return $payout;
    }

    public static function cancel(CommissionPayout $payout): CommissionPayout
    {
        if ($payout->status === CommissionPayout::STATUS_PAID) {
            throw new ApiException(422, 'Cannot cancel a payout that has already been paid.');
        }
        $payout->status = CommissionPayout::STATUS_CANCELLED;
        $payout->save();

        return $payout;
    }

    /**
     * A negative payout reversing commission earned on an invoice that
     * has since been written off (6.4).
     *
     * Auto-approved: it reduces what is owed, and asking someone to
     * approve taking money back would only delay the correction. Null
     * when nothing was ever earned on this invoice -- no rate set, no
     * salesperson on the contract, no receipts allocated, or a
     * commission of zero.
     */
    public static function createClawback(
        string $companyId,
        Invoice $invoice,
        string $userId,
        string $reason,
    ): ?CommissionPayout {
        $rate = Money::of(self::ratePercent($companyId));
        if ($rate->toFloat() == 0.0) {
            return null;
        }
        $contract = $invoice->contract_id !== null ? Contract::find($invoice->contract_id) : null;
        $salesStaffId = $contract?->sales_staff_id;
        if ($salesStaffId === null) {
            return null;
        }

        $allocations = PaymentAllocation::where('company_id', $companyId)
            ->where('invoice_id', $invoice->id)
            ->get();
        if ($allocations->isEmpty()) {
            return null;
        }

        $total = Money::of(0);
        foreach ($allocations as $allocation) {
            $total = $total->plus(self::commissionFor($allocation, $invoice, $rate));
        }
        if ($total->toFloat() == 0.0) {
            return null;
        }

        $month = Carbon::today()->format('Y-m');
        [$periodStart, $periodEnd] = self::monthRange($month);
        $now = Carbon::now();

        return CommissionPayout::create([
            'company_id' => $companyId,
            'payout_number' => Numbering::next($companyId, 'commission_payout'),
            'payout_type' => CommissionPayout::TYPE_CLAWBACK,
            'status' => CommissionPayout::STATUS_APPROVED,
            'sales_staff_id' => $salesStaffId,
            'period_month' => $month,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'amount_sgd' => Money::of(0)->minus($total)->toString(),
            'rate_percent' => $rate->toString(),
            'clawback_invoice_id' => $invoice->id,
            'clawback_reason' => $reason,
            'submitted_by_user_id' => $userId,
            'submitted_at' => $now,
            'approved_by_user_id' => $userId,
            'approved_at' => $now,
        ]);
    }

    private static function requireStatus(CommissionPayout $payout, string $required, string $verb): void
    {
        if ($payout->status !== $required) {
            throw new ApiException(422, 'Only '.strtoupper($required)." payouts can be {$verb}. "
                ."Current status: {$payout->status}");
        }
    }
}
