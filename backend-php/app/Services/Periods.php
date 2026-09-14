<?php

namespace App\Services;

use App\Exceptions\PeriodLockedError;
use App\Exceptions\YearEndClosingError;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\FiscalYearClosure;
use App\Models\JournalEntry;
use App\Models\PeriodLock;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Accounting Periods, per-document-type operation locks, and Year-End
 * (Fiscal Year) Closing. Mirrors backend/app/models/periods.py and
 * backend/app/services/periods.py exactly.
 *
 * Period locks (2026-09-12, confirmed with Dennis): each period
 * carries a matrix of locks -- one per document-type x operation
 * combination. Individual operations can be locked or unlocked
 * independently, replacing the old binary OPEN/CLOSED toggle. "Close
 * All" sets every lock; "Open All" clears every lock. The `status`
 * column stays on the period as a derived convenience indicator: OPEN
 * when no lock is set, CLOSED when every valid lock is set.
 *
 * Two pragmatic defaults (2026-09-11), still in effect:
 *  - Opt-in protection: a date with no period defined is unrestricted.
 *  - Fiscal year = whatever date range a period's rows say (plain
 *    date ranges, no hardcoded calendar).
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

    // Accounting document types that participate in the period lock
    // matrix. Mirrors backend/app/models/periods.py's PeriodDocType.
    public const DOC_SALES_INVOICE = 'sales_invoice';

    public const DOC_RECEIPT_VOUCHER = 'receipt_voucher';

    public const DOC_PAYMENT_VOUCHER = 'payment_voucher';

    public const DOC_PURCHASE_BILL = 'purchase_bill';

    public const DOC_JOURNAL_VOUCHER = 'journal_voucher';

    // Operations that can be individually locked per period per doc
    // type. Mirrors PeriodOperation.
    public const OP_UPDATE = 'update';

    public const OP_REVERSE = 'reverse';

    public const OP_BANK = 'bank';

    public const OP_UNBANK = 'unbank';

    public const OP_GL = 'gl';

    public const OP_UNGL = 'ungl';

    /**
     * Which operations are valid for which document type. Mirrors
     * backend/app/models/periods.py's VALID_DOC_OPERATIONS.
     */
    public const VALID_DOC_OPERATIONS = [
        self::DOC_SALES_INVOICE => [self::OP_UPDATE, self::OP_REVERSE, self::OP_GL, self::OP_UNGL],
        self::DOC_RECEIPT_VOUCHER => [self::OP_UPDATE, self::OP_REVERSE, self::OP_BANK, self::OP_UNBANK, self::OP_GL, self::OP_UNGL],
        self::DOC_PAYMENT_VOUCHER => [self::OP_UPDATE, self::OP_REVERSE, self::OP_BANK, self::OP_UNBANK, self::OP_GL, self::OP_UNGL],
        self::DOC_PURCHASE_BILL => [self::OP_UPDATE, self::OP_REVERSE, self::OP_GL, self::OP_UNGL],
        self::DOC_JOURNAL_VOUCHER => [self::OP_UPDATE, self::OP_REVERSE, self::OP_GL, self::OP_UNGL],
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

    /**
     * Backward-compatible wrapper: checks the old OPEN/CLOSED status.
     * New code should call requireAllows() with the specific doc_type
     * and operation. Mirrors Python's require_open_period -- kept for
     * parity even though (as in Python) nothing in this codebase calls
     * it: every real caller uses requireAllows() directly.
     */
    public static function requireOpenPeriod(string $companyId, Carbon $on): void
    {
        $period = self::getPeriodForDate($companyId, $on);
        if ($period !== null && $period->status === AccountingPeriod::STATUS_CLOSED) {
            throw new PeriodLockedError(sprintf(
                'The accounting period "%s" (%s to %s) is closed for posting. Ask an owner to reopen it under Accounting Periods, or use a date in an open period.',
                $period->name, $period->period_start->toDateString(), $period->period_end->toDateString(),
            ));
        }
    }

    /** Create one PeriodLock row for every valid doc-type x operation. */
    public static function seedLocksForPeriod(AccountingPeriod $period, bool $locked = false): void
    {
        foreach (self::VALID_DOC_OPERATIONS as $docType => $operations) {
            foreach ($operations as $operation) {
                PeriodLock::create([
                    'period_id' => $period->id,
                    'doc_type' => $docType,
                    'operation' => $operation,
                    'is_locked' => $locked,
                ]);
            }
        }
    }

    /** Derive the period's status from its lock rows. */
    private static function syncPeriodStatus(AccountingPeriod $period, ?string $actorUserId = null): void
    {
        $locks = PeriodLock::where('period_id', $period->id)->get();
        $allLocked = $locks->isNotEmpty() && $locks->every(fn (PeriodLock $lk) => $lk->is_locked);
        if ($allLocked) {
            $period->status = AccountingPeriod::STATUS_CLOSED;
            $period->closed_by_user_id = $actorUserId;
            if ($actorUserId !== null) {
                $period->closed_at = Carbon::now();
            }
        } else {
            $period->status = AccountingPeriod::STATUS_OPEN;
            if ($locks->every(fn (PeriodLock $lk) => ! $lk->is_locked)) {
                // Fully open -> clear the "last closed by" metadata.
                $period->closed_by_user_id = null;
                $period->closed_at = null;
            }
        }
    }

    /** Lock or unlock every operation for every doc type in one action. */
    public static function setAllLocks(AccountingPeriod $period, bool $locked, string $actorUserId): void
    {
        $now = Carbon::now();
        $locks = PeriodLock::where('period_id', $period->id)->get();
        foreach ($locks as $lk) {
            $lk->is_locked = $locked;
            $lk->locked_by_user_id = $locked ? $actorUserId : null;
            $lk->locked_at = $locked ? $now : null;
            $lk->save();
        }
        self::syncPeriodStatus($period, $actorUserId);
        $period->save();
    }

    /** Lock or unlock a single doc-type x operation cell. */
    public static function toggleLock(AccountingPeriod $period, string $docType, string $operation, bool $locked, string $actorUserId): PeriodLock
    {
        if (! in_array($operation, self::VALID_DOC_OPERATIONS[$docType] ?? [], true)) {
            throw new \InvalidArgumentException("{$operation} is not a valid operation for {$docType}");
        }
        $lock = PeriodLock::where('period_id', $period->id)
            ->where('doc_type', $docType)
            ->where('operation', $operation)
            ->first();
        if ($lock === null) {
            throw new \InvalidArgumentException("No lock row found for {$docType}/{$operation}");
        }
        $now = Carbon::now();
        $lock->is_locked = $locked;
        $lock->locked_by_user_id = $locked ? $actorUserId : null;
        $lock->locked_at = $locked ? $now : null;
        $lock->save();
        self::syncPeriodStatus($period, $actorUserId);
        $period->save();

        return $lock;
    }

    /** Lock every operation (Close All). */
    public static function closePeriod(AccountingPeriod $period, string $actorUserId): void
    {
        self::setAllLocks($period, true, $actorUserId);
    }

    /** Unlock every operation (Open All). */
    public static function reopenPeriod(AccountingPeriod $period): void
    {
        $now = Carbon::now();
        $locks = PeriodLock::where('period_id', $period->id)->get();
        foreach ($locks as $lk) {
            $lk->is_locked = false;
            $lk->locked_by_user_id = null;
            $lk->locked_at = null;
            $lk->save();
        }
        $period->status = AccountingPeriod::STATUS_OPEN;
        $period->closed_by_user_id = null;
        $period->closed_at = null;
        $period->save();
        unset($now);
    }

    /**
     * Zero every Revenue/Expense account's movement for $fiscalYear
     * into $retainedEarningsAccountId with one balanced closing
     * journal entry, then record the closure. Every period tagged
     * with this fiscal year must already be closed -- this only moves
     * balances that are no longer expected to change.
     *
     * Reversible: the closing entry is an ordinary posted
     * JournalEntry, so undoing a mistaken close is done the same way
     * any posted voucher is corrected -- Ledger::reverseEntry() --
     * rather than a separate "unclose" mechanism.
     */
    public static function closeFiscalYear(string $companyId, int $fiscalYear, string $retainedEarningsAccountId, string $actorUserId): JournalEntry
    {
        $periods = AccountingPeriod::where('company_id', $companyId)->where('fiscal_year', $fiscalYear)->get();
        if ($periods->isEmpty()) {
            throw new YearEndClosingError("No accounting periods are defined for fiscal year {$fiscalYear}.");
        }
        if ($periods->contains(fn (AccountingPeriod $p) => $p->status !== AccountingPeriod::STATUS_CLOSED)) {
            throw new YearEndClosingError(
                "Every period in fiscal year {$fiscalYear} must be closed (all operations locked) before year-end closing."
            );
        }
        $already = FiscalYearClosure::where('company_id', $companyId)->where('fiscal_year', $fiscalYear)->first();
        if ($already !== null) {
            throw new YearEndClosingError("Fiscal year {$fiscalYear} has already been closed.");
        }

        $reAccount = Account::find($retainedEarningsAccountId);
        if ($reAccount === null || $reAccount->company_id !== $companyId) {
            throw new YearEndClosingError('Unknown Retained Earnings account.');
        }
        if ($reAccount->account_type !== Account::TYPE_EQUITY) {
            throw new YearEndClosingError("{$reAccount->code} {$reAccount->name} is not an Equity account.");
        }

        $fyStart = $periods->min('period_start');
        $fyEnd = $periods->max('period_end');
        $dayBefore = $fyStart->copy()->subDay();

        $balancesEnd = collect(Ledger::accountBalances($companyId, $fyEnd))->keyBy('account_id');
        $balancesBefore = collect(Ledger::accountBalances($companyId, $dayBefore))->keyBy('account_id');
        $accountsById = Account::where('company_id', $companyId)->get()->keyBy('id');

        $lines = [];
        $totalFyBalance = Money::of(0);
        foreach ($balancesEnd as $accountId => $endRow) {
            $account = $accountsById->get($accountId);
            if ($account === null || ! in_array($account->account_type, [Account::TYPE_REVENUE, Account::TYPE_EXPENSE], true)) {
                continue;
            }
            $before = $balancesBefore->get($accountId);
            $beforeBalance = $before !== null ? $before['balance_sgd'] : Money::of(0);
            $fyBalance = $endRow['balance_sgd']->minus($beforeBalance);
            if ($fyBalance->toFloat() === 0.0) {
                continue;
            }
            $totalFyBalance = $totalFyBalance->plus($fyBalance);
            if ($fyBalance->toFloat() > 0) {
                $lines[] = [
                    'account_id' => $accountId,
                    'debit_sgd' => '0.00',
                    'credit_sgd' => $fyBalance->toString(),
                    'description' => "FY{$fiscalYear} close: {$account->code} {$account->name}",
                ];
            } else {
                $lines[] = [
                    'account_id' => $accountId,
                    'debit_sgd' => $fyBalance->multipliedBy(-1)->toString(),
                    'credit_sgd' => '0.00',
                    'description' => "FY{$fiscalYear} close: {$account->code} {$account->name}",
                ];
            }
        }

        if ($lines === []) {
            throw new YearEndClosingError(
                "No Revenue or Expense activity found in fiscal year {$fiscalYear} -- nothing to close."
            );
        }

        if ($totalFyBalance->toFloat() > 0) {
            $lines[] = [
                'account_id' => $retainedEarningsAccountId,
                'debit_sgd' => $totalFyBalance->toString(),
                'credit_sgd' => '0.00',
                'description' => "FY{$fiscalYear} net loss to retained earnings",
            ];
        } else {
            $lines[] = [
                'account_id' => $retainedEarningsAccountId,
                'debit_sgd' => '0.00',
                'credit_sgd' => $totalFyBalance->multipliedBy(-1)->toString(),
                'description' => "FY{$fiscalYear} net profit to retained earnings",
            ];
        }

        return DB::transaction(function () use ($companyId, $fiscalYear, $fyEnd, $reAccount, $lines, $actorUserId, $retainedEarningsAccountId) {
            $entry = Ledger::createJournalEntry(
                companyId: $companyId,
                entryDate: $fyEnd,
                narration: "Year-end closing FY{$fiscalYear}: Revenue/Expense closed to {$reAccount->code} {$reAccount->name}",
                lines: $lines,
                voucherType: JournalEntry::TYPE_JOURNAL,
                createdByUserId: $actorUserId,
                sourceType: 'fiscal_year_closure',
            );
            Ledger::postEntry($entry, $actorUserId, bypassPeriodCheck: true);

            FiscalYearClosure::create([
                'company_id' => $companyId,
                'fiscal_year' => $fiscalYear,
                'retained_earnings_account_id' => $retainedEarningsAccountId,
                'closing_journal_entry_id' => $entry->id,
                'closed_by_user_id' => $actorUserId,
            ]);

            return $entry;
        });
    }
}
