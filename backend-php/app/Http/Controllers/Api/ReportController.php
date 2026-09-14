<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Services\Authority;
use App\Services\Ledger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Accounting Reports. Mirrors the trial-balance slice of
 * backend/app/routers/reports.py -- `_trial_balance_report`/
 * `trial_balance_report` (~line 603-660 there).
 *
 * FINDING (checked while converting Accounting Period management /
 * GL Trial Balance, 2026-09-14): this endpoint is a genuine duplicate
 * of App\Http\Controllers\Api\LedgerController::trialBalance() --
 * both call the identical App\Services\Ledger::accountBalances()
 * and build the identical TrialBalance shape. It is not dead code
 * though: it is registered under a different route
 * (`/reports/accounting/trial-balance` vs `/ledger/trial-balance`)
 * and, critically, gated by a *different* Module Control key
 * (`accounting_reports`, matching Python's `ACCOUNTING_MODULE`
 * constant in reports.py, vs `finance_accounting` for the General
 * Ledger screen) -- so a Group can be granted the Accounting Reports
 * screen without also being granted the General Ledger / Journal
 * Voucher screen, or vice versa. Both
 * frontend/src/pages/AccountingReportsPage.tsx (via `reportTrialBalance`)
 * and frontend/src/pages/GeneralLedgerPage.tsx (via `trialBalance`)
 * depend on their own route, so both are ported here, each with its
 * own small presentation helper -- mirroring Python's own structure
 * (reports.py and ledger.py each carry their own private
 * `_trial_balance_rows`/`_trial_balance_report` helper around the
 * same `account_balances()` call, rather than sharing one), not
 * silently merged into one.
 *
 * NOT yet converted: the rest of backend/app/routers/reports.py (AR/AP
 * aging duplicates already exist under their own modules' routes --
 * see AccountsReceivableController::agingReport()/
 * AccountsPayableController::agingReport() -- GST Return, Sales GP,
 * Operations Reports, dashboards, and CSV/Excel export for all of
 * these). That whole module is lower priority (see
 * docs/php-conversion-plan.md's "Not yet converted" list) -- only this
 * one endpoint was in scope for this task, because
 * AccountingReportsPage.tsx's trial balance view depends on it.
 */
class ReportController extends Controller
{
    private const MODULE = 'accounting_reports';

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
            'is_balanced' => $totalDebit === $totalCredit,
        ]);
    }
}
