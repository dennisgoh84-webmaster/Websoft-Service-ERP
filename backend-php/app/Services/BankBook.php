<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Bank Book balance calculations. Mirrors
 * backend/app/services/bank_book.py, and exists for the same reason:
 * the account list's current balance and the ledger's running balance
 * must be the same number, computed once.
 *
 * This is its own ledger, separate from the General Ledger's Journal
 * Vouchers -- see App\Models\BankTransaction for why.
 *
 * A VOIDED transaction never moves a balance. It stays visible in the
 * ledger (struck through) rather than disappearing, because CLAUDE.md
 * forbids deleting financial records.
 */
class BankBook
{
    /**
     * Opening balance plus every non-voided transaction up to and
     * including `asAt` (or all of them, when not given).
     */
    public static function currentBalance(BankAccount $bankAccount, ?Carbon $asAt = null): Money
    {
        $query = BankTransaction::where('bank_account_id', $bankAccount->id)
            ->where('is_voided', false);
        if ($asAt !== null) {
            $query->whereDate('transaction_date', '<=', $asAt->toDateString());
        }

        return self::sumInto(Money::of($bankAccount->opening_balance_sgd), $query->get());
    }

    /**
     * Opening balance plus only the transactions already ticked off
     * against a bank statement -- what a reconciliation compares with
     * the statement's own closing balance.
     */
    public static function reconciledBalance(string $bankAccountId, string|int|float $openingBalance): Money
    {
        $rows = BankTransaction::where('bank_account_id', $bankAccountId)
            ->where('is_voided', false)
            ->where('is_reconciled', true)
            ->get();

        return self::sumInto(Money::of($openingBalance), $rows);
    }

    /**
     * A foreign-currency account's balance in its own currency
     * (multi-currency): its opening balance there, plus each line's
     * amount in that currency.
     */
    public static function currentBalanceFx(BankAccount $bankAccount, ?Carbon $asAt = null): Money
    {
        $query = BankTransaction::where('bank_account_id', $bankAccount->id)->where('is_voided', false);
        if ($asAt !== null) {
            $query->whereDate('transaction_date', '<=', $asAt->toDateString());
        }

        return $query->get()->reduce(
            fn (Money $carry, BankTransaction $t) => $carry->plus(Money::of($t->debit_fx ?? 0))->minus(Money::of($t->credit_fx ?? 0)),
            Money::of($bankAccount->opening_balance_fx ?? 0),
        );
    }

    /** @param Collection<int, BankTransaction> $rows */
    private static function sumInto(Money $opening, $rows): Money
    {
        return $rows->reduce(
            fn (Money $carry, BankTransaction $t) => $carry
                ->plus(Money::of($t->debit_sgd))
                ->minus(Money::of($t->credit_sgd)),
            $opening,
        );
    }
}
