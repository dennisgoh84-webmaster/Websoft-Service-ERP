<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * NEW FEATURE (not a Python->PHP conversion -- backend/ has no
 * equivalent Sales Dashboard endpoint at all yet; built directly in
 * backend-php per Dennis's request, see docs/backlog.md /
 * docs/planned-work.md): "Sales Dashboard - Display below Company
 * Dashboard".
 *
 * PRAGMATIC DEFAULT / KNOWN GAP, flagged for Dennis's confirmation per
 * CLAUDE.md's "never assume a business rule when requirements have
 * not been provided" -- NOT silently baked in as a confirmed rule:
 * "This Financial Year" is taken as the CALENDAR year (1 Jan - 31 Dec
 * of the given/current year). No fiscal-year-start field exists
 * anywhere in the system (Company has no such setting) to derive a
 * real financial year from.
 *
 * KNOWN GAP: "No. of Quotations Pending for Approval" / "... Pending
 * for Confirmation by Client" always report 0 / not_available=true --
 * the Sales Quotation module exists in backend/ (Python) but has not
 * been converted to backend-php yet (this work is scoped to
 * backend-php only, per its task brief), and even in the Python
 * source, Quotation has no "pending approval" or "pending client
 * confirmation" status distinct from its existing draft/sent/accepted/
 * rejected/expired states -- so these two figures cannot be
 * meaningfully computed from any existing data model. Never fabricated.
 */
class SalesDashboardService
{
    /**
     * The company's financial year, as a date range.
     *
     * CONFIRMED 2026-09-15, replacing the calendar-year assumption this
     * service previously carried as a KNOWN GAP ("no fiscal-year-start
     * field exists anywhere in the system"). It does now:
     * `companies.financial_year_start_month`.
     *
     * A FINANCIAL YEAR IS LABELLED BY THE CALENDAR YEAR IT ENDS IN, so
     * with a July start, FY2027 runs 1 Jul 2026 - 30 Jun 2027. A
     * January start makes the financial year identical to the calendar
     * year, which is why the old behaviour is still exactly reproduced
     * for any company configured that way.
     *
     * @return array{start: Carbon, end: Carbon}
     */
    public static function financialYearRange(string $companyId, ?int $year = null): array
    {
        $startMonth = (int) (Company::find($companyId)?->financial_year_start_month ?? 1);
        $startMonth = $startMonth >= 1 && $startMonth <= 12 ? $startMonth : 1;

        $year ??= self::currentFinancialYear($companyId);

        // Labelled by the ending year, so the range STARTS in the
        // previous calendar year unless the year begins in January.
        $start = $startMonth === 1
            ? Carbon::create($year, 1, 1)->startOfDay()
            : Carbon::create($year - 1, $startMonth, 1)->startOfDay();

        return [
            'start' => $start,
            'end' => $start->copy()->addYear()->subDay()->endOfDay(),
        ];
    }

    /**
     * Which financial year today falls in, by that same
     * labelled-by-its-end rule.
     */
    public static function currentFinancialYear(string $companyId): int
    {
        $startMonth = (int) (Company::find($companyId)?->financial_year_start_month ?? 1);
        $today = Carbon::today();

        // On or after the start month, we are already in the year that
        // ends next calendar year.
        return $startMonth === 1 || $today->month < $startMonth
            ? (int) $today->year
            : (int) $today->year + 1;
    }

    public static function contractsDueForRenewalCount(string $companyId): int
    {
        return ContractService::dueForRenewal($companyId)->count();
    }

    /**
     * All currently-outstanding invoices with their aging bucket, for
     * the AR KPI tiles' drill-down and the total/2-month/3-month
     * figures themselves -- reuses
     * App\Services\AccountsReceivableService::agingBucketFor() so this
     * can never disagree with the AR Aging report's own bucketing.
     *
     * @return Collection<int, array{invoice_id: string, invoice_number: string, customer_id: string, customer_name: string, due_date: ?string, outstanding_sgd: float, bucket: string}>
     */
    public static function outstandingInvoiceRows(string $companyId, ?Carbon $asAt = null): Collection
    {
        $asAt = $asAt ?? Carbon::today();
        $invoices = Invoice::where('company_id', $companyId)
            ->whereNotIn('status', [Invoice::STATUS_PAID, Invoice::STATUS_WRITTEN_OFF])
            ->get();
        $customerNames = CompanyIndividual::where('company_id', $companyId)->pluck('name', 'id');

        return $invoices
            ->map(function (Invoice $invoice) use ($asAt, $customerNames) {
                $outstanding = $invoice->outstandingSgd();
                if ($outstanding->toFloat() <= 0) {
                    return null;
                }

                return [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'customer_id' => $invoice->customer_id,
                    'customer_name' => $customerNames->get($invoice->customer_id, '(unknown)'),
                    'due_date' => optional($invoice->due_date)->toDateString(),
                    'outstanding_sgd' => $outstanding->toFloat(),
                    'bucket' => AccountsReceivableService::agingBucketFor($invoice->due_date, $asAt),
                ];
            })
            ->filter()
            ->values();
    }

    /** Sum of outstanding balances in one aging bucket, or 'total' for every bucket combined. */
    public static function arOutstandingSum(string $companyId, string $bucket = 'total', ?Carbon $asAt = null): float
    {
        $rows = self::outstandingInvoiceRows($companyId, $asAt);
        if ($bucket !== 'total') {
            $rows = $rows->where('bucket', $bucket);
        }

        return round((float) $rows->sum('outstanding_sgd'), 2);
    }

    /**
     * "Top 10 Sales Billing Customer for this Financial Year" -- ranked
     * by total invoiced revenue NET OF GST (Invoice::amount_sgd, not
     * total_amount_sgd, which includes GST).
     *
     * @return Collection<int, array{customer_id: string, customer_name: string, invoice_count: int, net_revenue_sgd: float}>
     */
    public static function topBillingCustomers(string $companyId, ?int $year = null, int $limit = 10): Collection
    {
        ['start' => $start, 'end' => $end] = self::financialYearRange($companyId, $year);
        $invoices = Invoice::where('company_id', $companyId)
            ->whereBetween('issued_at', [$start, $end])
            ->get();
        $customerNames = CompanyIndividual::where('company_id', $companyId)->pluck('name', 'id');

        $byCustomer = $invoices->groupBy('customer_id')->map(function ($group, $customerId) use ($customerNames) {
            return [
                'customer_id' => $customerId,
                'customer_name' => $customerNames->get($customerId, '(unknown)'),
                'invoice_count' => $group->count(),
                'net_revenue_sgd' => round((float) $group->sum(fn (Invoice $i) => (float) $i->amount_sgd), 2),
            ];
        })->values();

        return $byCustomer->sortByDesc('net_revenue_sgd')->take($limit)->values();
    }

    /**
     * "Bottom 10 Non Active Customer Listing for this Financial Year"
     * -- customers (is_customer=true) with ZERO invoices in the
     * financial year. Ordered alphabetically (there is no natural
     * "most bottom" ordering among a set that all tie at zero).
     *
     * @return Collection<int, array{customer_id: string, customer_name: string}>
     */
    public static function bottomNonActiveCustomers(string $companyId, ?int $year = null, int $limit = 10): Collection
    {
        ['start' => $start, 'end' => $end] = self::financialYearRange($companyId, $year);
        $billedCustomerIds = Invoice::where('company_id', $companyId)
            ->whereBetween('issued_at', [$start, $end])
            ->pluck('customer_id')
            ->unique();

        return CompanyIndividual::where('company_id', $companyId)
            ->where('is_customer', true)
            ->whereNotIn('id', $billedCustomerIds)
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (CompanyIndividual $c) => ['customer_id' => $c->id, 'customer_name' => $c->name])
            ->values();
    }

    /**
     * KNOWN GAP -- see class docblock. Always 0/not-available; never
     * fabricated.
     */
    public static function quotationsPendingApproval(string $companyId): array
    {
        return ['count' => 0, 'not_available' => true, 'reason' => 'Quotations module not yet converted to backend-php.'];
    }

    public static function quotationsPendingConfirmation(string $companyId): array
    {
        return ['count' => 0, 'not_available' => true, 'reason' => 'Quotations module not yet converted to backend-php.'];
    }
}
