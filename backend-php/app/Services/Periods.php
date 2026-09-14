<?php

namespace App\Services;

use App\Exceptions\PeriodLockedError;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\PeriodLock;
use Illuminate\Support\Carbon;

/**
 * Accounting Period locking (granular, per-doc-type per-operation).
 * Mirrors backend/app/services/periods.py's lock-check logic exactly
 * -- see app/models/periods.py's docstring for the lock matrix and
 * the two pragmatic defaults (opt-in protection; fiscal year is
 * whatever the period rows say).
 *
 * NOT yet converted: Period management itself -- creating/closing/
 * reopening a period, toggling individual locks, and Year-End
 * Closing (backend/app/services/periods.py is 368 lines; the router
 * another 252). Since nothing in `backend-php/` creates an
 * AccountingPeriod row yet, requireAllows() below always finds none
 * and is correctly a no-op -- this is Python's own "opt-in
 * protection: a date with no period defined is unrestricted" default,
 * just never yet exercised. See docs/php-conversion-plan.md.
 */
class Periods
{
    private const VOUCHER_TO_DOC_TYPE = [
        JournalEntry::TYPE_JOURNAL => 'journal_voucher',
        JournalEntry::TYPE_RECEIPT => 'receipt_voucher',
        JournalEntry::TYPE_PAYMENT => 'payment_voucher',
        JournalEntry::TYPE_SALES_INVOICE => 'sales_invoice',
        JournalEntry::TYPE_PURCHASE_INVOICE => 'purchase_bill',
    ];

    public static function voucherTypeToDocType(string $voucherType): string
    {
        return self::VOUCHER_TO_DOC_TYPE[$voucherType] ?? 'journal_voucher';
    }

    public static function getPeriodForDate(string $companyId, Carbon $on): ?AccountingPeriod
    {
        return AccountingPeriod::where('company_id', $companyId)
            ->where('period_start', '<=', $on->toDateString())
            ->where('period_end', '>=', $on->toDateString())
            ->first();
    }

    /**
     * Raise PeriodLockedError if $operation is locked for $docType in
     * the period covering $on. A date with no period defined is
     * unrestricted (opt-in protection).
     */
    public static function requireAllows(string $companyId, Carbon $on, string $docType, string $operation): void
    {
        $period = self::getPeriodForDate($companyId, $on);
        if ($period === null) {
            return;
        }
        $lock = PeriodLock::where('period_id', $period->id)
            ->where('doc_type', $docType)
            ->where('operation', $operation)
            ->where('is_locked', true)
            ->first();
        if ($lock !== null) {
            $opLabel = strtoupper($operation);
            $docLabel = ucwords(str_replace('_', ' ', $docType));
            throw new PeriodLockedError(sprintf(
                '%s is locked for %s in period "%s" (%s to %s). Unlock it under Accounting Periods, or use a date in an unlocked period.',
                $opLabel, $docLabel, $period->name, $period->period_start->toDateString(), $period->period_end->toDateString(),
            ));
        }
    }
}
