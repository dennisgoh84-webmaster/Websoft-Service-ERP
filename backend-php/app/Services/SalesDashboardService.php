<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Prospect;
use App\Models\Quotation;
use App\Models\User;
use App\Support\Money;
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
 * "No. of Quotations Pending for Approval" / "... Pending for
 * Confirmation by Client": real counts since 2026-09-15, when the
 * Quotation status model gained pending_approval and approved
 * (BILL-006) -- pending approval is the former, sent-not-yet-accepted
 * the latter. Until then they reported not-available rather than a
 * fabricated figure.
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
     * total_amount_sgd, which includes GST), less the net of any credit
     * notes issued on them (BILL-003).
     *
     * @return Collection<int, array{customer_id: string, customer_name: string, invoice_count: int, net_revenue_sgd: float}>
     */
    public static function topBillingCustomers(string $companyId, ?int $year = null, int $limit = 10): Collection
    {
        ['start' => $start, 'end' => $end] = self::financialYearRange($companyId, $year);
        $invoices = Invoice::with(['creditNotes' => fn ($q) => $q->where('status', CreditNote::STATUS_ISSUED)])
            ->where('company_id', $companyId)
            ->whereBetween('issued_at', [$start, $end])
            ->get();
        $customerNames = CompanyIndividual::where('company_id', $companyId)->pluck('name', 'id');

        $byCustomer = $invoices->groupBy('customer_id')->map(function ($group, $customerId) use ($customerNames) {
            return [
                'customer_id' => $customerId,
                'customer_name' => $customerNames->get($customerId, '(unknown)'),
                'invoice_count' => $group->count(),
                'net_revenue_sgd' => round((float) $group->sum(fn (Invoice $i) => (float) $i->amount_sgd - (float) $i->creditNotes->sum('amount_sgd')), 2),
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
     * Quotations awaiting the Sales Manager's approval (BILL-006) --
     * status pending_approval. Real since the status model was settled
     * 2026-09-15; the KNOWN GAP that used to sit here is closed.
     */
    public static function quotationsPendingApproval(string $companyId): array
    {
        return [
            'count' => Quotation::where('company_id', $companyId)->where('status', Quotation::STATUS_PENDING_APPROVAL)->count(),
            'not_available' => false,
        ];
    }

    /** Quotations with the customer, not yet accepted or rejected -- status sent. */
    public static function quotationsPendingConfirmation(string $companyId): array
    {
        return [
            'count' => Quotation::where('company_id', $companyId)->where('status', Quotation::STATUS_SENT)->count(),
            'not_available' => false,
        ];
    }

    /**
     * Per-salesperson cards (decision 12.2, Dennis 2026-09-26, #50).
     *
     * - **Who sees what:** managers see all; staff see their own.
     *   seesAllProspects() -- the owner, Sales Manager and Sales
     *   Supervisor -- see every card; anyone else sees only their own.
     * - **Whose card work counts on:** the prospect's salesperson.
     *   Quotations and invoices reach a salesperson only through their
     *   prospect. Work with no prospect goes on a "No prospect" card, and
     *   prospects with no salesperson on a "No salesperson" card; both
     *   are for managers only.
     * - **What each card shows:** prospects by stage are the pipeline as
     *   it stands. Quoted (sent or accepted quotations, by quotation
     *   date), billed and paid (invoices, by issue date) are each given
     *   for this calendar month and for the financial year to date
     *   (Dennis, 2026-09-26, #50: "This month, with the year beside
     *   it").
     *
     * @return list<array<string, mixed>>
     */
    public static function salespersonCards(User $viewer, ?int $year = null): array
    {
        $companyId = $viewer->company_id;
        ['start' => $from, 'end' => $to] = self::financialYearRange($companyId, $year);
        $all = $viewer->seesAllProspects();
        $key = fn (?string $id) => $id ?? 'none';

        $cards = [];
        $card = function (string $k) use (&$cards) {
            $zero = ['quoted' => Money::of(0), 'billed' => Money::of(0), 'paid' => Money::of(0)];
            $cards[$k] ??= ['prospects_by_stage' => array_fill_keys(Prospect::STATUSES, 0), 'year' => $zero, 'month' => $zero];

            return $k;
        };

        // Everyone in a sales role gets a card, even before their first prospect.
        if ($all) {
            User::where('company_id', $companyId)->where('is_active', true)
                ->whereIn('role', [User::ROLE_SALES_MANAGER, User::ROLE_SALES_SUPERVISOR, User::ROLE_SALES_STAFF])
                ->pluck('id')->each(fn ($id) => $card($id));
        } else {
            $card($viewer->id);
        }

        $prospects = Prospect::where('company_id', $companyId)
            ->when(! $all, fn ($q) => $q->where('salesperson_user_id', $viewer->id))
            ->selectRaw('salesperson_user_id, status, count(*) as n')->groupBy('salesperson_user_id', 'status')->get();
        foreach ($prospects as $row) {
            $k = $card($key($row->salesperson_user_id));
            $cards[$k]['prospects_by_stage'][$row->status] = (int) $row->n;
        }

        // The same sums for the financial year and for this calendar month.
        $periods = ['year' => [$from, $to], 'month' => [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()]];
        foreach ($periods as $period => [$pFrom, $pTo]) {
            $quoted = Quotation::query()->leftJoin('prospects', 'prospects.id', '=', 'quotations.prospect_id')
                ->where('quotations.company_id', $companyId)
                ->whereIn('quotations.status', Prospect::QUOTED_STATUSES)
                ->whereBetween('quotations.quotation_date', [$pFrom->toDateString(), $pTo->toDateString()])
                ->when(! $all, fn ($q) => $q->where('prospects.salesperson_user_id', $viewer->id))
                ->selectRaw('quotations.prospect_id is null as no_prospect, prospects.salesperson_user_id, sum(quotations.total_amount_sgd) as total')
                ->groupByRaw('quotations.prospect_id is null, prospects.salesperson_user_id')->get();
            foreach ($quoted as $row) {
                $k = $card($row->no_prospect ? 'no_prospect' : $key($row->salesperson_user_id));
                $cards[$k][$period]['quoted'] = $cards[$k][$period]['quoted']->plus(Money::of($row->total ?? 0));
            }

            $billed = Invoice::query()->leftJoin('prospects', 'prospects.id', '=', 'invoices.prospect_id')
                ->where('invoices.company_id', $companyId)
                ->whereBetween('invoices.issued_at', [$pFrom, $pTo])
                ->when(! $all, fn ($q) => $q->where('prospects.salesperson_user_id', $viewer->id))
                ->selectRaw('invoices.prospect_id is null as no_prospect, prospects.salesperson_user_id, sum(invoices.total_amount_sgd - invoices.credited_sgd) as billed, sum(invoices.amount_paid_sgd) as paid')
                ->groupByRaw('invoices.prospect_id is null, prospects.salesperson_user_id')->get();
            foreach ($billed as $row) {
                $k = $card($row->no_prospect ? 'no_prospect' : $key($row->salesperson_user_id));
                $cards[$k][$period]['billed'] = $cards[$k][$period]['billed']->plus(Money::of($row->billed ?? 0));
                $cards[$k][$period]['paid'] = $cards[$k][$period]['paid']->plus(Money::of($row->paid ?? 0));
            }
        }

        $people = User::whereIn('id', array_filter(array_keys($cards), fn ($k) => $k !== 'none' && $k !== 'no_prospect'))->get(['id', 'full_name', 'role'])->keyBy('id');
        $names = $people->map(fn (User $u) => $u->full_name);
        $out = [];
        foreach ($cards as $k => $c) {
            $out[] = [
                'salesperson_user_id' => in_array($k, ['none', 'no_prospect'], true) ? null : $k,
                'kind' => $k === 'none' ? 'no_salesperson' : ($k === 'no_prospect' ? 'no_prospect' : 'salesperson'),
                'role' => $people[$k]->role ?? null,
                'name' => match ($k) {
                    'none' => 'No salesperson', 'no_prospect' => 'No prospect', default => $names[$k] ?? 'Unknown'
                },
                'prospects_by_stage' => $c['prospects_by_stage'],
                'open_prospects' => array_sum(array_intersect_key($c['prospects_by_stage'], array_flip(Prospect::ACTIVE_STATUSES))),
                // The financial year to date ...
                'quoted_sgd' => $c['year']['quoted']->toFloat(),
                'billed_sgd' => $c['year']['billed']->toFloat(),
                'paid_sgd' => $c['year']['paid']->toFloat(),
                // ... and this calendar month.
                'quoted_month_sgd' => $c['month']['quoted']->toFloat(),
                'billed_month_sgd' => $c['month']['billed']->toFloat(),
                'paid_month_sgd' => $c['month']['paid']->toFloat(),
            ];
        }
        // Salespeople by what they billed, then the two catch-all cards last.
        usort($out, fn ($a, $b) => [$a['kind'] !== 'salesperson', -$a['billed_sgd'], $a['name']] <=> [$b['kind'] !== 'salesperson', -$b['billed_sgd'], $b['name']]);

        return $out;
    }
}
