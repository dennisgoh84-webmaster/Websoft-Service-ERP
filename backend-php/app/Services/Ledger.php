<?php

namespace App\Services;

use App\Exceptions\LedgerRuleViolation;
use App\Exceptions\PeriodClosedError;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * General ledger posting. Mirrors backend/app/services/ledger.py --
 * see that file's docstring: a voucher may not be posted unless its
 * debits equal its credits, and once posted it is immutable. A
 * mistake is corrected by reversing the voucher (an equal and
 * opposite entry), never by editing or deleting it.
 */
class Ledger
{
    /**
     * Create a DRAFT voucher. `lines` are arrays of account_id,
     * debit_sgd, credit_sgd and an optional description.
     *
     * `voucherNumber`: normally allocated here from the document
     * counter. Auto-posted entries (App\Services\Posting) pass their
     * source document's own number instead -- an RV's GL entry is
     * numbered like the RV -- so the ledger reads back to the
     * document and the RECEIPT/PAYMENT counters aren't consumed
     * twice per voucher.
     *
     * @param  array<int, array{account_id: string, debit_sgd?: string|int|float, credit_sgd?: string|int|float, description?: ?string}>  $lines
     */
    public static function createJournalEntry(
        string $companyId,
        Carbon $entryDate,
        string $narration,
        array $lines,
        string $voucherType = JournalEntry::TYPE_JOURNAL,
        ?string $createdByUserId = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $voucherNumber = null,
    ): JournalEntry {
        if ($lines === []) {
            throw new LedgerRuleViolation('A voucher needs at least one line.');
        }

        if ($voucherNumber === null) {
            $docKind = match ($voucherType) {
                JournalEntry::TYPE_RECEIPT => 'receipt',
                JournalEntry::TYPE_PAYMENT => 'payment',
                default => 'journal',
            };
            $voucherNumber = Numbering::next($companyId, $docKind, $entryDate);
        }

        $entry = JournalEntry::create([
            'company_id' => $companyId,
            'voucher_number' => $voucherNumber,
            'voucher_type' => $voucherType,
            'entry_date' => $entryDate->toDateString(),
            'narration' => $narration,
            'status' => JournalEntry::STATUS_DRAFT,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'created_by_user_id' => $createdByUserId,
        ]);

        foreach ($lines as $raw) {
            $debit = Money::of($raw['debit_sgd'] ?? 0);
            $credit = Money::of($raw['credit_sgd'] ?? 0);
            if ($debit->toFloat() < 0 || $credit->toFloat() < 0) {
                throw new LedgerRuleViolation('Debit and credit amounts cannot be negative.');
            }
            if ($debit->toFloat() > 0 && $credit->toFloat() > 0) {
                throw new LedgerRuleViolation('A line is either a debit or a credit, not both.');
            }
            if ($debit->toFloat() === 0.0 && $credit->toFloat() === 0.0) {
                throw new LedgerRuleViolation('Every line needs a debit or a credit amount.');
            }

            $account = Account::find($raw['account_id']);
            if ($account === null || $account->company_id !== $companyId) {
                throw new LedgerRuleViolation('Unknown account on one of the lines.');
            }
            if (! $account->is_active) {
                throw new LedgerRuleViolation("Account {$account->code} {$account->name} is retired and cannot be posted to.");
            }

            JournalLine::create([
                'entry_id' => $entry->id,
                'account_id' => $account->id,
                'debit_sgd' => $debit->toString(),
                'credit_sgd' => $credit->toString(),
                'description' => $raw['description'] ?? null,
            ]);
        }

        return $entry->fresh('lines');
    }

    /**
     * Post a draft voucher to the ledger. Refuses to post anything
     * that doesn't balance, or anything dated inside a closed
     * accounting period. `bypassPeriodCheck` exists only for the
     * Year-End Closing voucher itself (App\Services\Periods::
     * closeFiscalYear()), which is deliberately dated inside a period
     * that is closed by definition.
     */
    public static function postEntry(JournalEntry $entry, ?string $actorUserId, bool $bypassPeriodCheck = false): JournalEntry
    {
        if ($entry->status === JournalEntry::STATUS_POSTED) {
            throw new LedgerRuleViolation('That voucher is already posted.');
        }
        if ($entry->status === JournalEntry::STATUS_REVERSED) {
            throw new LedgerRuleViolation('That voucher has been reversed.');
        }

        if (! $bypassPeriodCheck) {
            try {
                Periods::requireAllows(
                    $entry->company_id, $entry->entry_date,
                    Periods::voucherTypeToDocType($entry->voucher_type), 'gl',
                );
            } catch (PeriodClosedError $e) {
                throw new LedgerRuleViolation($e->getMessage());
            }
        }

        $totalDebit = $entry->totalDebit();
        $totalCredit = $entry->totalCredit();
        if ($totalDebit->toFloat() !== $totalCredit->toFloat()) {
            throw new LedgerRuleViolation(sprintf(
                'Voucher does not balance: debits SGD %s vs credits SGD %s.',
                $totalDebit->toString(), $totalCredit->toString(),
            ));
        }
        if ($totalDebit->toFloat() <= 0) {
            throw new LedgerRuleViolation('A voucher must move a non-zero amount.');
        }

        $entry->status = JournalEntry::STATUS_POSTED;
        $entry->posted_by_user_id = $actorUserId;
        $entry->posted_at = Carbon::now();
        $entry->save();

        return $entry;
    }

    /**
     * Reverse a posted voucher by writing its mirror image. The
     * original is left exactly as it was and marked reversed; the new
     * entry carries the opposite debits and credits.
     */
    public static function reverseEntry(JournalEntry $entry, string $actorUserId, string $reason, ?string $voucherNumber = null): JournalEntry
    {
        if ($entry->status !== JournalEntry::STATUS_POSTED) {
            throw new LedgerRuleViolation('Only a posted voucher can be reversed.');
        }
        if (trim($reason) === '') {
            throw new LedgerRuleViolation('A reason is required to reverse a voucher.');
        }

        try {
            Periods::requireAllows(
                $entry->company_id, $entry->entry_date,
                Periods::voucherTypeToDocType($entry->voucher_type), 'reverse',
            );
        } catch (PeriodClosedError $e) {
            throw new LedgerRuleViolation($e->getMessage());
        }

        $lines = $entry->lines->map(fn (JournalLine $l) => [
            'account_id' => $l->account_id,
            // Swapped: what was debited is credited back.
            'debit_sgd' => $l->credit_sgd,
            'credit_sgd' => $l->debit_sgd,
            'description' => $l->description,
        ])->all();

        $reversal = self::createJournalEntry(
            companyId: $entry->company_id,
            entryDate: Carbon::today(),
            narration: "Reversal of {$entry->voucher_number}: {$reason}",
            lines: $lines,
            voucherType: $entry->voucher_type,
            createdByUserId: $actorUserId,
            // Not linked via source_type/source_id -- see
            // reverses_entry_id below; a reversal carrying the source
            // would count as the document's live posting under
            // uq_journal_entries_live_source and block the re-post
            // that UNGL allows (gl-posting-design.md §4.6).
            sourceType: null,
            sourceId: null,
            voucherNumber: $voucherNumber,
        );
        $reversal->reverses_entry_id = $entry->id;
        $reversal->save();
        self::postEntry($reversal, $actorUserId);

        $entry->status = JournalEntry::STATUS_REVERSED;
        $entry->save();

        return $reversal;
    }

    /**
     * GL transaction ledger for one account: every posted journal line
     * touching this account, ordered by date then voucher number, with
     * a running balance. Mirrors backend/app/services/ledger.py's
     * account_transactions() -- including that function's own choice
     * to convert to float here (not at the controller boundary), since
     * this is a read-only report row, not a value fed back into
     * further arithmetic.
     *
     * The running balance is cumulative debit minus credit, starting
     * at zero (or at the brought-forward total when $dateFrom is set).
     *
     * @return list<array{line_id: string, entry_id: string, voucher_number: string, voucher_type: string, entry_date: string, narration: string, line_description: ?string, debit_sgd: float, credit_sgd: float, balance_sgd: float}>
     */
    public static function accountTransactions(string $companyId, string $accountId, ?Carbon $dateFrom = null, ?Carbon $dateTo = null): array
    {
        $account = Account::find($accountId);
        if ($account === null || $account->company_id !== $companyId) {
            return [];
        }

        // ── Opening balance when a date_from filter is set ──
        $openingBalance = Money::of(0);
        if ($dateFrom !== null) {
            $priorLines = JournalLine::where('account_id', $accountId)
                ->whereHas('entry', function ($q) use ($companyId, $dateFrom) {
                    $q->where('company_id', $companyId)
                        ->where('status', JournalEntry::STATUS_POSTED)
                        ->where('entry_date', '<', $dateFrom->toDateString());
                })
                ->get();
            foreach ($priorLines as $line) {
                $openingBalance = $openingBalance->plus(Money::of($line->debit_sgd))->minus(Money::of($line->credit_sgd));
            }
        }

        // ── Transaction lines in the date window ──
        $lines = JournalLine::where('account_id', $accountId)
            ->whereHas('entry', function ($q) use ($companyId, $dateFrom, $dateTo) {
                $q->where('company_id', $companyId)->where('status', JournalEntry::STATUS_POSTED);
                if ($dateFrom !== null) {
                    $q->where('entry_date', '>=', $dateFrom->toDateString());
                }
                if ($dateTo !== null) {
                    $q->where('entry_date', '<=', $dateTo->toDateString());
                }
            })
            ->with('entry')
            ->get()
            ->all();

        usort($lines, function (JournalLine $a, JournalLine $b) {
            $cmp = $a->entry->entry_date <=> $b->entry->entry_date;

            return $cmp !== 0 ? $cmp : $a->entry->voucher_number <=> $b->entry->voucher_number;
        });

        $running = $openingBalance;
        $rows = [];
        foreach ($lines as $line) {
            $entry = $line->entry;
            $debit = Money::of($line->debit_sgd);
            $credit = Money::of($line->credit_sgd);
            $running = $running->plus($debit)->minus($credit);
            $rows[] = [
                'line_id' => $line->id,
                'entry_id' => $entry->id,
                'voucher_number' => $entry->voucher_number,
                'voucher_type' => $entry->voucher_type,
                'entry_date' => $entry->entry_date->toDateString(),
                'narration' => $entry->narration,
                'line_description' => $line->description,
                'debit_sgd' => $debit->toFloat(),
                'credit_sgd' => $credit->toFloat(),
                'balance_sgd' => $running->toFloat(),
            ];
        }

        return $rows;
    }

    /**
     * Trial balance: every account's posted debits and credits. Draft
     * and reversed vouchers are excluded -- only posted entries are
     * part of the ledger. Mirrors backend/app/services/ledger.py's
     * account_balances() -- unlike accountTransactions() above, this
     * keeps debit/credit/balance as Money (Python keeps them as
     * Decimal), only converted to float at the controller boundary,
     * since App\Services\Periods::closeFiscalYear() does further
     * arithmetic on the result (a fiscal year's movement = one
     * as-at balance minus another).
     *
     * @return list<array{account_id: string, code: string, name: string, account_type: string, debit_sgd: Money, credit_sgd: Money, balance_sgd: Money}>
     */
    public static function accountBalances(string $companyId, ?Carbon $asAt = null): array
    {
        $lines = JournalLine::whereHas('entry', function ($q) use ($companyId, $asAt) {
            $q->where('company_id', $companyId)->where('status', JournalEntry::STATUS_POSTED);
            if ($asAt !== null) {
                $q->where('entry_date', '<=', $asAt->toDateString());
            }
        })->with('account')->get();

        $totals = [];
        foreach ($lines as $line) {
            $account = $line->account;
            if ($account === null) {
                continue;
            }
            if (! isset($totals[$account->id])) {
                $totals[$account->id] = [
                    'account_id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'account_type' => $account->account_type,
                    'debit_sgd' => Money::of(0),
                    'credit_sgd' => Money::of(0),
                ];
            }
            $totals[$account->id]['debit_sgd'] = $totals[$account->id]['debit_sgd']->plus(Money::of($line->debit_sgd));
            $totals[$account->id]['credit_sgd'] = $totals[$account->id]['credit_sgd']->plus(Money::of($line->credit_sgd));
        }

        $rows = array_values($totals);
        usort($rows, fn (array $a, array $b) => $a['code'] <=> $b['code']);
        foreach ($rows as &$row) {
            $row['balance_sgd'] = $row['debit_sgd']->minus($row['credit_sgd']);
        }
        unset($row);

        return $rows;
    }
}
