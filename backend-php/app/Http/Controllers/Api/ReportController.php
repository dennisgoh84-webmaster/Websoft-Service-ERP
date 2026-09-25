<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ScopesReportCompanies;
use App\Http\Controllers\Api\Concerns\SendsExports;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\CommissionSettings;
use App\Models\CompanyIndividual;
use App\Models\GroupModuleAuthority;
use App\Models\User;
use App\Services\AccountsReceivableService;
use App\Services\Audit;
use App\Services\Authority;
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
    use ScopesReportCompanies;
    use SendsExports;

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

    // ── Scope: which of the user's companies, and which parties ─────
    //
    // Every report below takes `company_ids` (comma-separated; defaults
    // to the user's current company) so one report can cover several of
    // the user's own companies at once (Dennis, 2026-09-24: "company
    // selection, and multiple company selection"). Each id must be one
    // this user can switch to (CompanyController::accessibleCompanyIds)
    // and, for anyone but the owner, have this module enabled -- the
    // same two checks that already guard the current company.
    // Customer / supplier / salesperson filters are applied to the
    // rows the shared services return, so the figures stay the ones
    // the AR / AP / Commission screens themselves show.

    /** @return array<string, string> company id => "C001 Name", in the order requested */
    private function companyScope(Request $request, User $user): array
    {
        return $this->reportCompanyScope($request, $user, self::MODULE, 'Accounting Reports');
    }

    /** @return array<int, string> */
    private function idList(Request $request, string $key): array
    {
        $raw = $request->query($key);
        $parts = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_unique(array_filter(array_map('trim', $parts), fn ($v) => $v !== '')));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function onlyIds(array $rows, string $field, array $ids): array
    {
        if ($ids === []) {
            return $rows;
        }

        return array_values(array_filter($rows, fn (array $r) => in_array((string) ($r[$field] ?? ''), $ids, true)));
    }

    /** Export columns: a Company column first, but only when more than one company is in the report. */
    private function withCompanyColumn(array $fields, array $scope): array
    {
        return count($scope) > 1 ? ['company_name', ...$fields] : $fields;
    }

    /**
     * Choices for the Company / Individual and salesperson filters across
     * the selected internal companies. One party list, not separate
     * customer and supplier lists: the same Company / Individual can be
     * both (Dennis, 2026-09-24).
     */
    public function filterOptions(Request $request)
    {
        $user = $this->viewer($request);
        $scope = $this->companyScope($request, $user);
        $ids = array_keys($scope);
        $multi = count($ids) > 1;
        $label = fn (string $name, string $companyId) => $multi ? "{$name} ({$this->companyCode($scope[$companyId] ?? '')})" : $name;

        return response()->json([
            'company_individuals' => CompanyIndividual::whereIn('company_id', $ids)->orderBy('name')->get(['id', 'name', 'company_id'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $label($c->name, $c->company_id)])
                ->values(),
            'sales_staff' => User::whereIn('company_id', $ids)->where('is_active', true)->orderBy('full_name')
                ->get(['id', 'full_name', 'company_id'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $label($u->full_name, $u->company_id)])
                ->values(),
        ]);
    }

    private function companyCode(string $label): string
    {
        return strtok($label, ' ') ?: $label;
    }

    // ── Trial balance ───────────────────────────────────────────────

    /**
     * One row per account. Across several companies accounts are
     * matched by code + name (each company keeps its own chart of
     * accounts) and their figures added together.
     *
     * @return array<int, array<string, mixed>>
     */
    private function trialBalanceRows(array $scope, ?Carbon $asAt): array
    {
        $byKey = [];
        foreach (array_keys($scope) as $companyId) {
            foreach (Ledger::accountBalances($companyId, $asAt) as $r) {
                $key = $r['code']."\u{1F}".$r['name'];
                if (! isset($byKey[$key])) {
                    $byKey[$key] = [
                        'account_id' => $r['account_id'],
                        'code' => $r['code'],
                        'name' => $r['name'],
                        'account_type' => $r['account_type'],
                        'debit_sgd' => 0.0,
                        'credit_sgd' => 0.0,
                        'balance_sgd' => 0.0,
                    ];
                }
                $byKey[$key]['debit_sgd'] += $r['debit_sgd']->toFloat();
                $byKey[$key]['credit_sgd'] += $r['credit_sgd']->toFloat();
                $byKey[$key]['balance_sgd'] += $r['balance_sgd']->toFloat();
            }
        }
        $rows = array_values($byKey);
        foreach ($rows as &$row) {
            foreach (['debit_sgd', 'credit_sgd', 'balance_sgd'] as $f) {
                $row[$f] = round($row[$f], 2);
            }
        }
        unset($row);
        usort($rows, fn ($a, $b) => strcmp((string) $a['code'], (string) $b['code']));

        return $rows;
    }

    public function trialBalance(Request $request)
    {
        $user = $this->viewer($request);

        return response()->json($this->trialBalanceReport($this->companyScope($request, $user), $this->asAt($request)));
    }

    public function trialBalanceCsv(Request $request)
    {
        $user = $this->viewer($request);
        $rows = $this->trialBalanceReport($this->companyScope($request, $user), $this->asAt($request))['rows'];
        $this->auditExport($user, 'Accounting Report: Trial Balance', 'csv', count($rows));

        return $this->csvResponse(self::TRIAL_BALANCE_FIELDS, $rows, 'trial-balance-report.csv');
    }

    public function trialBalanceExcel(Request $request)
    {
        $user = $this->viewer($request);
        $rows = $this->trialBalanceReport($this->companyScope($request, $user), $this->asAt($request))['rows'];
        $this->auditExport($user, 'Accounting Report: Trial Balance', 'excel', count($rows));

        return $this->xlsxResponse(self::TRIAL_BALANCE_FIELDS, $rows, 'Trial Balance', 'trial-balance-report.xlsx');
    }

    /** @return array<string, mixed> */
    private function trialBalanceReport(array $scope, ?Carbon $asAt): array
    {
        $rows = $this->trialBalanceRows($scope, $asAt);
        $totalDebit = round(array_sum(array_column($rows, 'debit_sgd')), 2);
        $totalCredit = round(array_sum(array_column($rows, 'credit_sgd')), 2);

        return [
            'as_at' => $asAt?->toDateString(),
            'companies' => array_values($scope),
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

        return response()->json($this->arAgingReport($request, $this->companyScope($request, $user)));
    }

    public function arAgingCsv(Request $request)
    {
        $user = $this->viewer($request);
        $scope = $this->companyScope($request, $user);
        $rows = $this->arAgingReport($request, $scope)['rows'];
        $this->auditExport($user, 'Accounting Report: AR Aging', 'csv', count($rows));

        return $this->csvResponse($this->withCompanyColumn(self::AR_AGING_FIELDS, $scope), $rows, 'ar-aging-report.csv');
    }

    public function arAgingExcel(Request $request)
    {
        $user = $this->viewer($request);
        $scope = $this->companyScope($request, $user);
        $rows = $this->arAgingReport($request, $scope)['rows'];
        $this->auditExport($user, 'Accounting Report: AR Aging', 'excel', count($rows));

        return $this->xlsxResponse($this->withCompanyColumn(self::AR_AGING_FIELDS, $scope), $rows, 'AR Aging', 'ar-aging-report.xlsx');
    }

    /** @return array<string, mixed> */
    private function arAgingReport(Request $request, array $scope): array
    {
        $asAt = $this->asAt($request);
        $customerIds = $this->idList($request, 'customer_ids');
        $rows = [];
        $resolvedAsAt = null;
        foreach ($scope as $companyId => $companyName) {
            [$resolvedAsAt, $companyRows] = AccountsReceivableService::agingRows($companyId, $asAt);
            foreach ($this->onlyIds($companyRows, 'customer_id', $customerIds) as $r) {
                $rows[] = ['company_id' => $companyId, 'company_name' => $companyName, ...$r];
            }
        }

        return [
            'as_at' => ($resolvedAsAt ?? $asAt ?? Carbon::today())->toDateString(),
            'companies' => array_values($scope),
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

        return response()->json($this->apAgingReport($request, $this->companyScope($request, $user)));
    }

    public function apAgingCsv(Request $request)
    {
        $user = $this->viewer($request);
        $scope = $this->companyScope($request, $user);
        $rows = $this->apAgingReport($request, $scope)['rows'];
        $this->auditExport($user, 'Accounting Report: AP Aging', 'csv', count($rows));

        return $this->csvResponse($this->withCompanyColumn(self::AP_AGING_FIELDS, $scope), $rows, 'ap-aging-report.csv');
    }

    public function apAgingExcel(Request $request)
    {
        $user = $this->viewer($request);
        $scope = $this->companyScope($request, $user);
        $rows = $this->apAgingReport($request, $scope)['rows'];
        $this->auditExport($user, 'Accounting Report: AP Aging', 'excel', count($rows));

        return $this->xlsxResponse($this->withCompanyColumn(self::AP_AGING_FIELDS, $scope), $rows, 'AP Aging', 'ap-aging-report.xlsx');
    }

    /** @return array<string, mixed> */
    private function apAgingReport(Request $request, array $scope): array
    {
        $asAt = $this->asAt($request);
        $supplierIds = $this->idList($request, 'supplier_ids');
        $rows = [];
        $resolvedAsAt = null;
        foreach ($scope as $companyId => $companyName) {
            [$resolvedAsAt, $companyRows] = PayablesService::agingRows($companyId, $asAt);
            foreach ($this->onlyIds($companyRows, 'supplier_id', $supplierIds) as $r) {
                $rows[] = ['company_id' => $companyId, 'company_name' => $companyName, ...$r];
            }
        }

        return [
            'as_at' => ($resolvedAsAt ?? $asAt ?? Carbon::today())->toDateString(),
            'companies' => array_values($scope),
            'rows' => $rows,
            'total' => array_sum(array_column($rows, 'total')),
        ];
    }

    // ── GST Return ──────────────────────────────────────────────────

    public function gstReturn(Request $request)
    {
        $user = $this->viewer($request);
        [$start, $end] = $this->period($request);

        return response()->json($this->gstReturnReport($this->companyScope($request, $user), $start, $end));
    }

    public function gstReturnCsv(Request $request)
    {
        $user = $this->viewer($request);
        $rows = $this->gstReturnRows($request, $user);
        $this->auditExport($user, 'Accounting Report: GST Return', 'csv', count($rows));

        return $this->csvResponse(self::GST_FIELDS, $rows, 'gst-return.csv');
    }

    public function gstReturnExcel(Request $request)
    {
        $user = $this->viewer($request);
        $rows = $this->gstReturnRows($request, $user);
        $this->auditExport($user, 'Accounting Report: GST Return', 'excel', count($rows));

        return $this->xlsxResponse(self::GST_FIELDS, $rows, 'GST Return', 'gst-return.xlsx');
    }

    /**
     * The shared service's figures per company, added together per tax
     * code when several companies are selected (a group-level view --
     * each company still files its own return).
     *
     * @return array<string, mixed>
     */
    private function gstReturnReport(array $scope, Carbon $start, Carbon $end): array
    {
        $merge = function (array $into, array $rows): array {
            foreach ($rows as $r) {
                $code = $r['tax_code'];
                if (! isset($into[$code])) {
                    $into[$code] = ['tax_code' => $code, 'net_sgd' => 0.0, 'tax_sgd' => 0.0, 'document_count' => 0];
                }
                $into[$code]['net_sgd'] = round($into[$code]['net_sgd'] + $r['net_sgd'], 2);
                $into[$code]['tax_sgd'] = round($into[$code]['tax_sgd'] + $r['tax_sgd'], 2);
                $into[$code]['document_count'] += $r['document_count'];
            }

            return $into;
        };
        $output = [];
        $input = [];
        $totalOutput = 0.0;
        $totalInput = 0.0;
        foreach (array_keys($scope) as $companyId) {
            $r = ReportsService::gstReturn($companyId, $start, $end);
            $output = $merge($output, $r['output_rows']);
            $input = $merge($input, $r['input_rows']);
            $totalOutput += $r['total_output_tax_sgd'];
            $totalInput += $r['total_input_tax_sgd'];
        }

        return [
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'companies' => array_values($scope),
            'output_rows' => array_values($output),
            'input_rows' => array_values($input),
            'total_output_tax_sgd' => round($totalOutput, 2),
            'total_input_tax_sgd' => round($totalInput, 2),
            // Negative means reclaimable.
            'net_gst_payable_sgd' => round($totalOutput - $totalInput, 2),
        ];
    }

    /**
     * Output and input rows flattened into one sheet, each tagged with
     * the direction it came from -- a spreadsheet has one table where
     * the screen has two.
     *
     * @return array<int, array<string, mixed>>
     */
    private function gstReturnRows(Request $request, User $user): array
    {
        [$start, $end] = $this->period($request);
        $report = $this->gstReturnReport($this->companyScope($request, $user), $start, $end);

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

        return response()->json($this->salesGpReport($request, $this->companyScope($request, $user), $start, $end));
    }

    public function salesGpCsv(Request $request)
    {
        $user = $this->viewer($request);
        $scope = $this->companyScope($request, $user);
        $rows = $this->salesGpExportRows($request, $scope);
        $this->auditExport($user, 'Accounting Report: Sales GP', 'csv', count($rows));

        return $this->csvResponse($this->withCompanyColumn(self::SALES_GP_FIELDS, $scope), $rows, 'sales-gp-report.csv');
    }

    public function salesGpExcel(Request $request)
    {
        $user = $this->viewer($request);
        $scope = $this->companyScope($request, $user);
        $rows = $this->salesGpExportRows($request, $scope);
        $this->auditExport($user, 'Accounting Report: Sales GP', 'excel', count($rows));

        return $this->xlsxResponse($this->withCompanyColumn(self::SALES_GP_FIELDS, $scope), $rows, 'Sales GP', 'sales-gp-report.xlsx');
    }

    /** @return array<string, mixed> */
    private function salesGpReport(Request $request, array $scope, Carbon $start, Carbon $end): array
    {
        $customerIds = $this->idList($request, 'customer_ids');
        $rows = [];
        foreach ($scope as $companyId => $companyName) {
            $names = ReportsService::customerNames($companyId);
            foreach ($this->onlyIds(ReportsService::salesGpRows($companyId, $start, $end), 'customer_id', $customerIds) as $r) {
                $rows[] = [
                    'company_id' => $companyId,
                    'company_name' => $companyName,
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
                ];
            }
        }

        $totalRevenue = round(array_sum(array_column($rows, 'revenue_sgd')), 2);
        $totalCost = round(array_sum(array_column($rows, 'cost_sgd')), 2);
        $totalGp = round($totalRevenue - $totalCost, 2);

        return [
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'companies' => array_values($scope),
            'rows' => $rows,
            'total_revenue_sgd' => $totalRevenue,
            'total_cost_sgd' => $totalCost,
            'total_gp_sgd' => $totalGp,
            'total_gp_percent' => $totalRevenue == 0.0 ? 0.0 : round($totalGp / $totalRevenue * 100, 2),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function salesGpExportRows(Request $request, array $scope): array
    {
        [$start, $end] = $this->period($request);

        return array_map(fn (array $r) => [
            'company_name' => $r['company_name'],
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
        ], $this->salesGpReport($request, $scope, $start, $end)['rows']);
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

        return response()->json($this->commissionReport($request, $user, $this->companyScope($request, $user), $start, $end));
    }

    public function commissionCsv(Request $request)
    {
        $user = $this->viewer($request);
        $scope = $this->companyScope($request, $user);
        $rows = $this->commissionExportRows($request, $user, $scope);
        $this->auditExport($user, 'Accounting Report: Commission', 'csv', count($rows));

        return $this->csvResponse($this->withCompanyColumn(self::COMMISSION_FIELDS, $scope), $rows, 'commission-report.csv');
    }

    public function commissionExcel(Request $request)
    {
        $user = $this->viewer($request);
        $scope = $this->companyScope($request, $user);
        $rows = $this->commissionExportRows($request, $user, $scope);
        $this->auditExport($user, 'Accounting Report: Commission', 'excel', count($rows));

        return $this->xlsxResponse($this->withCompanyColumn(self::COMMISSION_FIELDS, $scope), $rows, 'Commission', 'commission-report.xlsx');
    }

    /**
     * Each company's commission at its own rate. `rate_percent` is the
     * user's CURRENT company's rate -- the one the rate box on screen
     * edits.
     *
     * @return array<string, mixed>
     */
    private function commissionReport(Request $request, User $user, array $scope, Carbon $start, Carbon $end): array
    {
        // 'unassigned' selects the rows with no salesperson on the contract.
        $staffIds = $this->idList($request, 'sales_staff_ids');
        $rows = [];
        foreach ($scope as $companyId => $companyName) {
            $rawRows = ReportsService::commissionRows($companyId, $start, $end);
            $names = ReportsService::userNames(array_column($rawRows, 'sales_staff_id'));
            foreach ($rawRows as $r) {
                if ($staffIds !== [] && ! in_array($r['sales_staff_id'] ?? 'unassigned', $staffIds, true)) {
                    continue;
                }
                $rows[] = [
                    'company_id' => $companyId,
                    'company_name' => $companyName,
                    'month' => $r['month'],
                    'sales_staff_id' => $r['sales_staff_id'],
                    // An invoice whose contract names no salesperson still
                    // earns commission -- it is reported against "Unassigned"
                    // rather than dropped.
                    'sales_staff_name' => $r['sales_staff_id'] === null
                        ? 'Unassigned'
                        : ($names[$r['sales_staff_id']] ?? 'Unassigned'),
                    'commission_sgd' => $r['commission_sgd'],
                ];
            }
        }

        return [
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'companies' => array_values($scope),
            'rate_percent' => (float) ReportsService::commissionRatePercent($user->company_id),
            'rows' => $rows,
            'total_commission_sgd' => round(array_sum(array_column($rows, 'commission_sgd')), 2),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function commissionExportRows(Request $request, User $user, array $scope): array
    {
        [$start, $end] = $this->period($request);

        return array_map(fn (array $r) => [
            'company_name' => $r['company_name'],
            'month' => $r['month'],
            'sales_staff_name' => $r['sales_staff_name'],
            'commission_sgd' => $r['commission_sgd'],
        ], $this->commissionReport($request, $user, $scope, $start, $end)['rows']);
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
}
