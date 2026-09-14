<?php

namespace App\Services;

use App\Exceptions\ARRuleViolation;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Accounts Receivable business logic. Mirrors
 * backend/app/services/accounts_receivable.py -- see that file's
 * docstring for AR-001/002/003.
 *
 * KNOWN GAP, deliberately not silently papered over: this class only
 * ports the invoice-side rules (AR-002 write-off, AR-003 dispute
 * flagging) and the AR-003 aging report. AR-001 (recording a customer
 * Payment and manually allocating it against invoices) is NOT
 * converted yet -- Payment.bank_account_id is a required, non-null
 * foreign key into `bank_accounts` (docs/gl-posting-design.md's Bank
 * step), and that table doesn't exist in `backend-php/` until GL
 * posting + Bank is converted (see docs/php-conversion-plan.md's
 * "Not yet converted" list). Recording a receipt through
 * `backend-php/` is not possible yet; do not treat an invoice's
 * `amount_paid_sgd`/status here as reflecting real payments.
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
     * AR-002. The owner can always write off. Anyone else can only do
     * so below the configured threshold -- and while no threshold is
     * set (it was never decided, open item 3.4), nobody else can.
     */
    public static function canWriteOff(string $companyId, User $actor, Money $amount): bool
    {
        if ($actor->role === User::ROLE_OWNER) {
            return true;
        }
        $company = Company::find($companyId);
        $threshold = $company?->write_off_approval_threshold_sgd;
        if ($threshold === null) {
            return false;
        }

        return $amount->toFloat() <= Money::of($threshold)->toFloat();
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
        if (! self::canWriteOff($invoice->company_id, $actor, $outstanding)) {
            $company = Company::find($invoice->company_id);
            $threshold = $company?->write_off_approval_threshold_sgd;
            if ($threshold === null) {
                throw new ARRuleViolation(
                    'No write-off approval threshold has been set, so every write-off needs '.
                    "the owner's approval (AR-002). Set a threshold in Company Setup, or ask ".
                    'the owner to action this.'
                );
            }
            throw new ARRuleViolation(sprintf(
                'SGD %s is above the SGD %s write-off threshold -- the owner must approve this (AR-002).',
                $outstanding->toString(), Money::of($threshold)->toString(),
            ));
        }

        $invoice->status = Invoice::STATUS_WRITTEN_OFF;
        $invoice->save();

        return $invoice;
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
}
