<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\CommissionSettings;
use App\Models\GroupModuleAuthority;
use App\Models\User;
use App\Services\AccountsReceivableService;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\Exports;
use App\Services\Ledger;
use App\Services\PayablesService;
use App\Services\ReportsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Accounting Reports. Mirrors the `/accounting/*` half of
 * backend/app/routers/reports.py -- AR and AP aging, the trial
 * balance, the GST return, Sales GP, and Commission (with its rate
 * setting), each as JSON plus CSV and XLSX export.
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
 * AR and AP aging read through the same shared services the AR and AP
 * screens use (App\Services\AccountsReceivableService::agingRows,
 * App\Services\PayablesService::agingRows) rather than through a
 * second copy of the bucketing loop, as Python's reports.py keeps.
 * The figures on this screen and on those screens are then the same
 * figures, not two implementations that agree today.
 *
 * The Operations half of reports.py lives in
 * App\Http\Controllers\Api\OperationsReportController.
 *
 * EVERY EXPORT IS AUDITED with the report name, format and row count,
 * matching Python's `_audit_export`.
 */
class ReportController extends Controller
{
    private const MODULE = 'accounting_reports';

    /** @var array<int, string> */
    private const TRIAL_BALANCE_FIELDS = ['code', 'name', 'account_type', 'debit_sgd', 'credit_sgd', 'balance_sgd'];

    /** @var array<int, string> */
    private const AR_AGING_FIELDS = [
        'customer_name', 'current', 'days_1_30', 'days_31_60', 'days_61_90', 'over_90', 'total',
    ];

    /** @var array<int, string> */
    private const AP_AGING_FIELDS = [
        'supplier_name', 'current', 'days_1_30', 'days_31_60', 'days_61_90', 'over_90', 'total',
    ];

    /** @var array<int, string> */
    private const GST_FIELDS = ['tax_code', 'net_sgd', 'tax_sgd', 'document_count', 'direction'];

    /** @var array<int, string> */
    private const SALES_GP_FIELDS = [
        'invoice_number', 'issued_date', 'customer_name', 'revenue_sgd', 'cost_sgd', 'gp_sgd', 'gp_percent',
    ];

    /** @var array<int, string> */
    private const COMMISSION_FIELDS = ['month', 'sales_staff_name', 'commission_sgd'];

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
        $user = $this->viewer($request);

        return response()->json($this->trialBalanceReport($user->company_id, $this->asAt($request)));
    }

    public function trialBalanceCsv(Request $request)
    {
        $user = $this->viewer($request);
        $rows = $this->trialBalanceReport($user->company_id, $this->asAt($request))['rows'];
        $this->auditExport($user, 'Accounting Report: Trial Balance', 'csv', count($rows));

        return $this->csv(Exports::rowsToCsv(self::TRIAL_BALANCE_FIELDS, $rows), 'trial-balance-report.csv');
    }

    public function trialBalanceExcel(Request $request)
    {
        $user = $this->viewer($request);
        $rows = $this->trialBalanceReport($user->company_id, $this->asAt($request))['rows'];
        $this->auditExport($user, 'Accounting Report: Trial Balance', 'excel', count($rows));

        return $this->xlsx(
            Exports::rowsToExcel(self::TRIAL_BALANCE_FIELDS, $rows, 'Trial Balance'),
            'trial-balance-report.xlsx'
        );
    }

    /** @return array<string, mixed> */
    private function trialBalanceReport(string $companyId, ?Carbon $asAt): array
    {
        $rows = $this->trialBalanceRows($companyId, $asAt);
        $totalDebit = round(array_sum(array_column($rows, 'debit_sgd')), 2);
        $totalCredit = round(array_sum(array_column($rows, 'credit_sgd')), 2);

        return [
            'as_at' => $asAt?->toDateString(),
            'rows' => $rows,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'is_balanced' => $totalDebit === $totalCredit,
        ];
    }

    // ── AR / AP aging ───────────────────────────────────────────────

    public function arAging(Request $request)
    {
        $user = $this->viewer($request);

        return response()->json($this->arAgingReport($user->company_id, $this->asAt($request)));
    }

    public function arAgingCsv(Request $request)
    {
        $user = $this->viewer($request);
        $rows = $this->arAgingReport($user->company_id, $this->asAt($request))['rows'];
        $this->auditExport($user, 'Accounting Report: AR Aging', 'csv', count($rows));

        return $this->csv(Exports::rowsToCsv(self::AR_AGING_FIELDS, $rows), 'ar-aging-report.csv');
    }

    public function arAgingExcel(Request $request)
    {
        $user = $this->viewer($request);
        $rows = $this->arAgingReport($user->company_id, $this->asAt($request))['rows'];
        $this->auditExport($user, 'Accounting Report: AR Aging', 'excel', count($rows));

        return $this->xlsx(Exports::rowsToExcel(self::AR_AGING_FIELDS, $rows, 'AR Aging'), 'ar-aging-report.xlsx');
    }

    /** @return array<string, mixed> */
    private function arAgingReport(string $companyId, ?Carbon $asAt): array
    {
        [$resolvedAsAt, $rows] = AccountsReceivableService::agingRows($companyId, $asAt);

        return [
            'as_at' => $resolvedAsAt->toDateString(),
            'rows' => $rows,
            'current' => array_sum(array_column($rows, 'current')),
            'days_1_30' => array_sum(array_column($rows, 'days_1_30')),
            'days_31_60' => array_sum(array_column($rows, 'days_31_60')),
            'days_61_90' => array_sum(array_column($rows, 'days_61_90')),
            'over_90' => array_sum(array_column($rows, 'over_90')),
            'total' => array_sum(array_column($rows, 'total')),
        ];
    }

    public function apAging(Request $request)
    {
        $user = $this->viewer($request);

        return response()->json($this->apAgingReport($user->company_id, $this->asAt($request)));
    }

    public function apAgingCsv(Request $request)
    {
        $user = $this->viewer($request);
        $rows = $this->apAgingReport($user->company_id, $this->asAt($request))['rows'];
        $this->auditExport($user, 'Accounting Report: AP Aging', 'csv', count($rows));

        return $this->csv(Exports::rowsToCsv(self::AP_AGING_FIELDS, $rows), 'ap-aging-report.csv');
    }

    public function apAgingExcel(Request $request)
    {
        $user = $this->viewer($request);
        $rows = $this->apAgingReport($user->company_id, $this->asAt($request))['rows'];
        $this->auditExport($user, 'Accounting Report: AP Aging', 'excel', count($rows));

        return $this->xlsx(Exports::rowsToExcel(self::AP_AGING_FIELDS, $rows, 'AP Aging'), 'ap-aging-report.xlsx');
    }

    /** @return array<string, mixed> */
    private function apAgingReport(string $companyId, ?Carbon $asAt): array
    {
        [$resolvedAsAt, $rows] = PayablesService::agingRows($companyId, $asAt);

        return [
            'as_at' => $resolvedAsAt->toDateString(),
            'rows' => $rows,
            'total' => array_sum(array_column($rows, 'total')),
        ];
    }

    // ── GST Return ──────────────────────────────────────────────────

    public function gstReturn(Request $request)
    {
        $user = $this->viewer($request);
        [$start, $end] = $this->period($request);

        return response()->json(ReportsService::gstReturn($user->company_id, $start, $end));
    }

    public function gstReturnCsv(Request $request)
    {
        $user = $this->viewer($request);
        $rows = $this->gstReturnRows($request);
        $this->auditExport($user, 'Accounting Report: GST Return', 'csv', count($rows));

        return $this->csv(Exports::rowsToCsv(self::GST_FIELDS, $rows), 'gst-return.csv');
    }

    public function gstReturnExcel(Request $request)
    {
        $user = $this->viewer($request);
        $rows = $this->gstReturnRows($request);
        $this->auditExport($user, 'Accounting Report: GST Return', 'excel', count($rows));

        return $this->xlsx(Exports::rowsToExcel(self::GST_FIELDS, $rows, 'GST Return'), 'gst-return.xlsx');
    }

    /**
     * Output and input rows flattened into one sheet, each tagged with
     * the direction it came from -- a spreadsheet has one table where
     * the screen has two.
     *
     * @return array<int, array<string, mixed>>
     */
    private function gstReturnRows(Request $request): array
    {
        $user = Authenticate::user($request);
        [$start, $end] = $this->period($request);
        $report = ReportsService::gstReturn($user->company_id, $start, $end);

        return [
            ...array_map(fn (array $r) => [...$r, 'direction' => 'output'], $report['output_rows']),
            ...array_map(fn (array $r) => [...$r, 'direction' => 'input'], $report['input_rows']),
        ];
    }

    // ── Sales GP ────────────────────────────────────────────────────

    public function salesGp(Request $request)
    {
        $user = $this->viewer($request);
        [$start, $end] = $this->period($request);

        return response()->json($this->salesGpReport($user->company_id, $start, $end));
    }

    public function salesGpCsv(Request $request)
    {
        $user = $this->viewer($request);
        $rows = $this->salesGpExportRows($request);
        $this->auditExport($user, 'Accounting Report: Sales GP', 'csv', count($rows));

        return $this->csv(Exports::rowsToCsv(self::SALES_GP_FIELDS, $rows), 'sales-gp-report.csv');
    }

    public function salesGpExcel(Request $request)
    {
        $user = $this->viewer($request);
        $rows = $this->salesGpExportRows($request);
        $this->auditExport($user, 'Accounting Report: Sales GP', 'excel', count($rows));

        return $this->xlsx(Exports::rowsToExcel(self::SALES_GP_FIELDS, $rows, 'Sales GP'), 'sales-gp-report.xlsx');
    }

    /** @return array<string, mixed> */
    private function salesGpReport(string $companyId, Carbon $start, Carbon $end): array
    {
        $names = ReportsService::customerNames($companyId);
        $rows = array_map(fn (array $r) => [
            'invoice_id' => $r['invoice_id'],
            'invoice_number' => $r['invoice_number'],
            'issued_at' => optional($r['issued_at'])->toIso8601String(),
            'customer_id' => $r['customer_id'],
            'customer_name' => $names[$r['customer_id']] ?? '',
            'revenue_sgd' => $r['revenue_sgd'],
            'cost_sgd' => $r['cost_sgd'],
            'gp_sgd' => $r['gp_sgd'],
            'gp_percent' => $r['gp_percent'],
            'has_cost_basis' => $r['has_cost_basis'],
        ], ReportsService::salesGpRows($companyId, $start, $end));

        $totalRevenue = round(array_sum(array_column($rows, 'revenue_sgd')), 2);
        $totalCost = round(array_sum(array_column($rows, 'cost_sgd')), 2);
        $totalGp = round($totalRevenue - $totalCost, 2);

        return [
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'rows' => $rows,
            'total_revenue_sgd' => $totalRevenue,
            'total_cost_sgd' => $totalCost,
            'total_gp_sgd' => $totalGp,
            'total_gp_percent' => $totalRevenue == 0.0 ? 0.0 : round($totalGp / $totalRevenue * 100, 2),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function salesGpExportRows(Request $request): array
    {
        $user = Authenticate::user($request);
        [$start, $end] = $this->period($request);

        return array_map(fn (array $r) => [
            'invoice_number' => $r['invoice_number'],
            // The date only: an exact issue time is more than a
            // listing needs, and Python drops it here for the same
            // reason (openpyxl refuses a tz-aware datetime).
            'issued_date' => $r['issued_at'] === null ? '' : Carbon::parse($r['issued_at'])->toDateString(),
            'customer_name' => $r['customer_name'],
            'revenue_sgd' => $r['revenue_sgd'],
            'cost_sgd' => $r['cost_sgd'],
            'gp_sgd' => $r['gp_sgd'],
            'gp_percent' => $r['gp_percent'],
        ], $this->salesGpReport($user->company_id, $start, $end)['rows']);
    }

    // ── Commission ──────────────────────────────────────────────────

    public function commissionSettings(Request $request)
    {
        $user = $this->viewer($request);

        return response()->json([
            'rate_percent' => (float) ReportsService::commissionRatePercent($user->company_id),
        ]);
    }

    public function updateCommissionSettings(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::FULL);

        $data = $request->validate(['rate_percent' => 'required|numeric|min:0|max:100']);
        $oldRate = (float) ReportsService::commissionRatePercent($user->company_id);

        $settings = CommissionSettings::firstOrNew(['company_id' => $user->company_id]);
        $settings->company_id = $user->company_id;
        $settings->rate_percent = $data['rate_percent'];
        $settings->save();

        Audit::record(
            entityType: 'commission_settings',
            entityId: $user->company_id,
            action: 'updated',
            actorUserId: $user->id,
            oldValue: ['rate_percent' => $oldRate],
            newValue: ['rate_percent' => (float) $data['rate_percent']],
        );

        return response()->json(['rate_percent' => (float) $settings->rate_percent]);
    }

    public function commission(Request $request)
    {
        $user = $this->viewer($request);
        [$start, $end] = $this->period($request);

        return response()->json($this->commissionReport($user->company_id, $start, $end));
    }

    public function commissionCsv(Request $request)
    {
        $user = $this->viewer($request);
        $rows = $this->commissionExportRows($request);
        $this->auditExport($user, 'Accounting Report: Commission', 'csv', count($rows));

        return $this->csv(Exports::rowsToCsv(self::COMMISSION_FIELDS, $rows), 'commission-report.csv');
    }

    public function commissionExcel(Request $request)
    {
        $user = $this->viewer($request);
        $rows = $this->commissionExportRows($request);
        $this->auditExport($user, 'Accounting Report: Commission', 'excel', count($rows));

        return $this->xlsx(Exports::rowsToExcel(self::COMMISSION_FIELDS, $rows, 'Commission'), 'commission-report.xlsx');
    }

    /** @return array<string, mixed> */
    private function commissionReport(string $companyId, Carbon $start, Carbon $end): array
    {
        $rawRows = ReportsService::commissionRows($companyId, $start, $end);
        $names = ReportsService::userNames(array_column($rawRows, 'sales_staff_id'));
        $rows = array_map(fn (array $r) => [
            'month' => $r['month'],
            'sales_staff_id' => $r['sales_staff_id'],
            // An invoice whose contract names no salesperson still
            // earns commission -- it is reported against "Unassigned"
            // rather than dropped.
            'sales_staff_name' => $r['sales_staff_id'] === null
                ? 'Unassigned'
                : ($names[$r['sales_staff_id']] ?? 'Unassigned'),
            'commission_sgd' => $r['commission_sgd'],
        ], $rawRows);

        return [
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'rate_percent' => (float) ReportsService::commissionRatePercent($companyId),
            'rows' => $rows,
            'total_commission_sgd' => round(array_sum(array_column($rows, 'commission_sgd')), 2),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function commissionExportRows(Request $request): array
    {
        $user = Authenticate::user($request);
        [$start, $end] = $this->period($request);

        return array_map(fn (array $r) => [
            'month' => $r['month'],
            'sales_staff_name' => $r['sales_staff_name'],
            'commission_sgd' => $r['commission_sgd'],
        ], $this->commissionReport($user->company_id, $start, $end)['rows']);
    }

    // ── Shared ──────────────────────────────────────────────────────

    private function viewer(Request $request): User
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::VIEW);

        return $user;
    }

    private function asAt(Request $request): ?Carbon
    {
        return $request->filled('as_at') ? Carbon::parse($request->query('as_at')) : null;
    }

    /**
     * Both date bounds are required on the period reports, as they are
     * in Python -- a GST return or a commission run over "everything"
     * is not a report anyone asked for.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'period_start' => 'required|date',
            'period_end' => 'required|date',
        ]);

        return [Carbon::parse($data['period_start']), Carbon::parse($data['period_end'])];
    }

    private function auditExport(User $user, string $reportName, string $format, int $rowCount): void
    {
        $plural = $rowCount === 1 ? 'row' : 'rows';
        Audit::recordReportGenerated(
            $user->id,
            $reportName,
            "{$reportName} exported as ".strtoupper($format)." ({$rowCount} {$plural})",
        );
    }

    private function csv(string $body, string $filename)
    {
        return response($body, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename={$filename}",
        ]);
    }

    private function xlsx(string $body, string $filename)
    {
        return response($body, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename={$filename}",
        ]);
    }
}
