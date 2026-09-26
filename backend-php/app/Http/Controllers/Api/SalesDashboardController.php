<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\SendsExports;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Company;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\SalesDashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * NEW FEATURE (not a Python->PHP conversion -- see
 * docs/backlog.md / docs/planned-work.md): "Sales Dashboard - Display
 * below Company Dashboard". See App\Services\SalesDashboardService for
 * the KPI computations, and its class docblock for the two documented
 * pragmatic defaults / known gaps this feature carries (calendar-year
 * "Financial Year", and the Quotations-pending KPIs always reporting
 * not_available).
 *
 * RBAC NOTE: the existing (Python-only, not yet converted to
 * backend-php) Company Dashboard is deliberately ungated -- any signed
 * -in user can see it (see backend/app/routers/dashboard.py, which
 * uses get_current_user, not require_module_access). This new Sales
 * Dashboard section is gated on the "reporting" module instead
 * ("Reporting / Management Dashboard" in the module catalog) since
 * RBAC is a hard CLAUDE.md requirement and there is no backend-php
 * precedent for this specific screen to follow -- flagged here as a
 * considered choice, not an oversight, in case Dennis wants it
 * ungated to match the Company Dashboard exactly instead.
 */
class SalesDashboardController extends Controller
{
    use SendsExports;

    private const MODULE = 'reporting';

    public function summary(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $year = $request->filled('year') ? (int) $request->query('year') : null;

        $startMonth = (int) (Company::find($user->company_id)?->financial_year_start_month ?? 1);

        return response()->json([
            'financial_year' => $year ?? SalesDashboardService::currentFinancialYear($user->company_id),
            'financial_year_is_calendar_year' => $startMonth === 1,
            'contracts_due_for_renewal' => SalesDashboardService::contractsDueForRenewalCount($user->company_id),
            'ar_outstanding_total_sgd' => SalesDashboardService::arOutstandingSum($user->company_id, 'total'),
            'ar_outstanding_2_months_sgd' => SalesDashboardService::arOutstandingSum($user->company_id, '31_60'),
            'ar_outstanding_3_months_sgd' => SalesDashboardService::arOutstandingSum($user->company_id, '61_90'),
            'quotations_pending_approval' => SalesDashboardService::quotationsPendingApproval($user->company_id),
            'quotations_pending_confirmation' => SalesDashboardService::quotationsPendingConfirmation($user->company_id),
        ]);
    }

    /** Per-salesperson cards (decision 12.2): managers see every card, anyone else only their own. */
    public function salespeople(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');
        $year = $request->filled('year') ? (int) $request->query('year') : null;

        return response()->json([
            'financial_year' => $year ?? SalesDashboardService::currentFinancialYear($user->company_id),
            'sees_all' => $user->seesAllProspects(),
            'month_label' => Carbon::now()->format('M Y'),
            'cards' => SalesDashboardService::salespersonCards($user, $year),
        ]);
    }

    private const AR_HEADERS = ['Invoice Number', 'Company / Individual', 'Due Date', 'Outstanding (SGD)', 'Aging Bucket'];

    public function arBreakdown(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $bucket = $request->query('bucket', 'total');
        $rows = SalesDashboardService::outstandingInvoiceRows($user->company_id);
        if ($bucket !== 'total') {
            $rows = $rows->where('bucket', $bucket);
        }

        return $rows->values();
    }

    public function exportArBreakdownCsv(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');
        Audit::recordReportGenerated($user->id, 'sales_dashboard_ar_breakdown', details: 'CSV export');

        return $this->csvTableResponse(self::AR_HEADERS, $this->arExportRows($request, $user->company_id), 'ar-outstanding-breakdown.csv');
    }

    public function exportArBreakdownExcel(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');
        Audit::recordReportGenerated($user->id, 'sales_dashboard_ar_breakdown', details: 'Excel export');

        return $this->xlsxTableResponse(self::AR_HEADERS, $this->arExportRows($request, $user->company_id), 'AR Outstanding', 'ar-outstanding-breakdown.xlsx');
    }

    private function arExportRows(Request $request, string $companyId): array
    {
        $bucket = $request->query('bucket', 'total');
        $rows = SalesDashboardService::outstandingInvoiceRows($companyId);
        if ($bucket !== 'total') {
            $rows = $rows->where('bucket', $bucket);
        }

        return $rows->map(fn ($r) => [
            $r['invoice_number'], $r['customer_name'], $r['due_date'], $r['outstanding_sgd'], $r['bucket'],
        ])->values()->all();
    }

    private const TOP_HEADERS = ['Company / Individual', 'Invoice Count', 'Net Revenue (SGD, excl. GST)'];

    public function topBillingCustomers(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $year = $request->filled('year') ? (int) $request->query('year') : null;

        return SalesDashboardService::topBillingCustomers($user->company_id, $year)->values();
    }

    public function exportTopBillingCustomersCsv(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');
        $year = $request->filled('year') ? (int) $request->query('year') : null;
        Audit::recordReportGenerated($user->id, 'sales_dashboard_top_billing_customers', details: 'CSV export');

        $rows = SalesDashboardService::topBillingCustomers($user->company_id, $year)
            ->map(fn ($r) => [$r['customer_name'], $r['invoice_count'], $r['net_revenue_sgd']])->values()->all();

        return $this->csvTableResponse(self::TOP_HEADERS, $rows, 'top-billing-customers.csv');
    }

    public function exportTopBillingCustomersExcel(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');
        $year = $request->filled('year') ? (int) $request->query('year') : null;
        Audit::recordReportGenerated($user->id, 'sales_dashboard_top_billing_customers', details: 'Excel export');

        $rows = SalesDashboardService::topBillingCustomers($user->company_id, $year)
            ->map(fn ($r) => [$r['customer_name'], $r['invoice_count'], $r['net_revenue_sgd']])->values()->all();

        return $this->xlsxTableResponse(self::TOP_HEADERS, $rows, 'Top Billing Customers', 'top-billing-customers.xlsx');
    }

    private const BOTTOM_HEADERS = ['Company / Individual'];

    public function bottomNonActiveCustomers(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $year = $request->filled('year') ? (int) $request->query('year') : null;

        return SalesDashboardService::bottomNonActiveCustomers($user->company_id, $year)->values();
    }

    public function exportBottomNonActiveCustomersCsv(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');
        $year = $request->filled('year') ? (int) $request->query('year') : null;
        Audit::recordReportGenerated($user->id, 'sales_dashboard_bottom_non_active_customers', details: 'CSV export');

        $rows = SalesDashboardService::bottomNonActiveCustomers($user->company_id, $year)
            ->map(fn ($r) => [$r['customer_name']])->values()->all();

        return $this->csvTableResponse(self::BOTTOM_HEADERS, $rows, 'bottom-non-active-customers.csv');
    }

    public function exportBottomNonActiveCustomersExcel(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');
        $year = $request->filled('year') ? (int) $request->query('year') : null;
        Audit::recordReportGenerated($user->id, 'sales_dashboard_bottom_non_active_customers', details: 'Excel export');

        $rows = SalesDashboardService::bottomNonActiveCustomers($user->company_id, $year)
            ->map(fn ($r) => [$r['customer_name']])->values()->all();

        return $this->xlsxTableResponse(self::BOTTOM_HEADERS, $rows, 'Bottom Non-Active Customers', 'bottom-non-active-customers.xlsx');
    }
}
