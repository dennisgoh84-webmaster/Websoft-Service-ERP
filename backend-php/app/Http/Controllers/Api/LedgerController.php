<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Account;
use App\Services\Authority;
use App\Services\Ledger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * General Ledger reporting: the trial balance and the per-account
 * transaction ledger (drill-down). Mirrors the trial-balance and
 * account-transactions halves of backend/app/routers/ledger.py -- see
 * that file's docstring: a Journal Voucher is the manual double
 * entry, and the posting rules for automatic entries live in
 * App\Services\Posting instead.
 *
 * NOT yet converted (out of this task's scope, tracked here rather
 * than silently dropped -- see docs/php-conversion-plan.md): the
 * manual Journal Voucher CRUD endpoints (list/create/get/post/reverse
 * a voucher, `/ledger/vouchers*`) and CSV/Excel export for either
 * report. App\Services\Ledger already has createJournalEntry()/
 * postEntry()/reverseEntry() (built for the GL posting + Bank module,
 * used internally by App\Services\Posting) -- only the *manual*
 * voucher-raising endpoints a person would use from the General
 * Ledger screen are still missing a controller.
 */
class LedgerController extends Controller
{
    private const MODULE = 'finance_accounting';

    private function accountOrFail(string $companyId, string $accountId): Account
    {
        $account = Account::find($accountId);
        if (! $account || $account->company_id !== $companyId) {
            throw new ApiException(404, 'Account not found');
        }

        return $account;
    }

    private function trialBalanceRows(string $companyId, ?Carbon $asAt): array
    {
        return array_map(fn (array $r) => [
            'account_id' => $r['account_id'],
            'code' => $r['code'],
            'name' => $r['name'],
            'account_type' => $r['account_type'],
            'debit_sgd' => $r['debit_sgd']->toFloat(),
            'credit_sgd' => $r['credit_sgd']->toFloat(),
            'balance_sgd' => $r['balance_sgd']->toFloat(),
        ], Ledger::accountBalances($companyId, $asAt));
    }

    /** Posted debits and credits per account. Draft and reversed vouchers are excluded. */
    public function trialBalance(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $asAt = $request->filled('as_at') ? Carbon::parse($request->query('as_at')) : null;
        $rows = $this->trialBalanceRows($user->company_id, $asAt);
        $totalDebit = round(array_sum(array_column($rows, 'debit_sgd')), 2);
        $totalCredit = round(array_sum(array_column($rows, 'credit_sgd')), 2);

        return response()->json([
            'as_at' => $asAt?->toDateString(),
            'rows' => $rows,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            // A trial balance that doesn't balance means something is
            // wrong with the ledger itself, so it is surfaced rather
            // than hidden.
            'is_balanced' => $totalDebit === $totalCredit,
        ]);
    }

    /**
     * GL transaction ledger for one account -- every posted
     * debit/credit with running balance. Use the account_id from the
     * trial balance or chart of accounts.
     */
    public function accountTransactions(Request $request, string $accountId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $account = $this->accountOrFail($user->company_id, $accountId);
        $dateFrom = $request->filled('date_from') ? Carbon::parse($request->query('date_from')) : null;
        $dateTo = $request->filled('date_to') ? Carbon::parse($request->query('date_to')) : null;

        $rows = Ledger::accountTransactions($user->company_id, $account->id, $dateFrom, $dateTo);
        $totalDebit = array_sum(array_column($rows, 'debit_sgd'));
        $totalCredit = array_sum(array_column($rows, 'credit_sgd'));
        $closingBalance = $rows === [] ? 0.0 : $rows[count($rows) - 1]['balance_sgd'];

        return response()->json([
            'account_id' => $account->id,
            'account_code' => $account->code,
            'account_name' => $account->name,
            'account_type' => $account->account_type,
            'date_from' => $dateFrom?->toDateString(),
            'date_to' => $dateTo?->toDateString(),
            'rows' => $rows,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'closing_balance' => $closingBalance,
        ]);
    }
}
