<?php

namespace App\Services;

use App\Models\CommissionSettings;
use App\Models\CompanyIndividual;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\JobOrder;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Models\ServiceRecord;
use App\Models\SetupListItem;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Management Reporting queries. Mirrors
 * backend/app/services/reports.py -- the filtered reads behind the
 * Operations and Accounting report screens.
 *
 * Read-only throughout: nothing here writes, so a report can never
 * change what it is reporting on.
 */
class ReportsService
{
    /** @return Collection<int, Contract> */
    public static function contracts(
        string $companyId,
        ?string $status = null,
        ?string $contractKind = null,
        ?string $customerId = null,
        ?int $expiringWithinDays = null,
        ?Carbon $startDate = null,
        ?Carbon $endDate = null,
    ): Collection {
        $query = Contract::where('company_id', $companyId);
        if ($status !== null) {
            $query->where('status', $status);
        }
        if ($contractKind !== null) {
            $query->where('contract_kind', $contractKind);
        }
        if ($customerId !== null) {
            $query->where('customer_id', $customerId);
        }
        // An overlap test, not a containment one: a contract counts if
        // any part of it falls inside the window.
        if ($startDate !== null) {
            $query->whereDate('end_date', '>=', $startDate->toDateString());
        }
        if ($endDate !== null) {
            $query->whereDate('start_date', '<=', $endDate->toDateString());
        }

        $contracts = $query->orderBy('end_date')->get();

        // Applied in PHP, as Python applies it in Python: "expiring
        // within N days" excludes anything ALREADY expired (the day
        // count must be >= 0), which a SQL BETWEEN would not express as
        // clearly.
        if ($expiringWithinDays !== null) {
            $today = Carbon::today();
            $contracts = $contracts->filter(function (Contract $c) use ($today, $expiringWithinDays) {
                $days = $today->diffInDays($c->end_date->copy()->startOfDay(), false);

                return $days >= 0 && $days <= $expiringWithinDays;
            })->values();
        }

        return $contracts;
    }

    /** @return Collection<int, JobOrder> */
    public static function jobOrders(
        string $companyId,
        ?string $status = null,
        ?string $customerId = null,
        ?string $assignedToUserId = null,
        bool $overdueOnly = false,
        ?Carbon $startDate = null,
        ?Carbon $endDate = null,
    ): Collection {
        $query = JobOrder::where('company_id', $companyId);
        if ($status !== null) {
            $query->where('status', $status);
        }
        if ($customerId !== null) {
            $query->where('customer_id', $customerId);
        }
        if ($assignedToUserId !== null) {
            $query->where('assigned_to_user_id', $assignedToUserId);
        }
        if ($startDate !== null) {
            $query->where('created_at', '>=', $startDate);
        }
        if ($endDate !== null) {
            $query->where('created_at', '<=', $endDate);
        }

        $orders = $query->orderByDesc('created_at')->get();

        if ($overdueOnly) {
            $today = Carbon::today();
            $orders = $orders->filter(fn (JobOrder $o) => $o->due_date !== null
                && $o->due_date->lt($today)
                && ! in_array($o->status, [JobOrder::STATUS_CLOSED, JobOrder::STATUS_VOID], true))->values();
        }

        return $orders;
    }

    /** @return Collection<int, ServiceRecord> */
    public static function serviceRecords(
        string $companyId,
        ?string $status = null,
        ?string $outcome = null,
        ?string $customerId = null,
        ?string $employeeUserId = null,
        ?Carbon $startDate = null,
        ?Carbon $endDate = null,
    ): Collection {
        $query = ServiceRecord::query()
            ->join('job_orders', 'job_orders.id', '=', 'service_records.job_order_id')
            ->where('service_records.company_id', $companyId)
            ->select('service_records.*');

        if ($status !== null) {
            $query->where('service_records.status', $status);
        }
        if ($outcome !== null) {
            $query->where('service_records.outcome', $outcome);
        }
        // Filtered through the JOB ORDER's customer -- a Service Record
        // has no customer of its own.
        if ($customerId !== null) {
            $query->where('job_orders.customer_id', $customerId);
        }
        if ($employeeUserId !== null) {
            $query->where('service_records.employee_user_id', $employeeUserId);
        }
        if ($startDate !== null) {
            $query->whereDate('service_records.work_date', '>=', $startDate->toDateString());
        }
        if ($endDate !== null) {
            $query->whereDate('service_records.work_date', '<=', $endDate->toDateString());
        }

        return $query->orderByDesc('service_records.work_date')->get();
    }

    /**
     * "Which customer is using which product" (confirmed 2026-09-11):
     * one row per (customer, product) currently covered under a
     * contract's Product Coverage. Filter by customer to see everything
     * they hold; filter by product to see who holds it -- and, against
     * the full customer list, who does not.
     *
     * Visibility only: no automated gap flagging or renewal reminders,
     * which are a separate and not-yet-scoped follow-up.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function customerProductUsage(
        string $companyId,
        ?string $customerId = null,
        ?string $productId = null,
        ?string $industryCode = null,
    ): array {
        $query = Contract::query()
            ->join('contract_products', 'contract_products.contract_id', '=', 'contracts.id')
            ->join('company_individuals', 'contracts.customer_id', '=', 'company_individuals.id')
            ->join('products', 'contract_products.product_id', '=', 'products.id')
            ->where('contracts.company_id', $companyId)
            ->select([
                'contracts.id as contract_id',
                'contracts.contract_number',
                'contracts.contract_kind',
                'contracts.status as contract_status',
                'contracts.start_date',
                'contracts.end_date',
                'company_individuals.id as customer_id',
                'company_individuals.name as customer_name',
                'company_individuals.industry_code',
                'products.id as product_id',
                'products.name as product_name',
            ]);

        if ($customerId !== null) {
            $query->where('company_individuals.id', $customerId);
        }
        if ($productId !== null) {
            $query->where('products.id', $productId);
        }
        if ($industryCode !== null) {
            $query->where('company_individuals.industry_code', $industryCode);
        }

        $rows = $query->orderBy('company_individuals.name')->orderBy('products.name')->get();

        $industryNames = SetupListItem::where('list_type', SetupListItem::TYPE_INDUSTRY)
            ->pluck('name', 'code');

        return $rows->map(fn ($r) => [
            'customer_id' => $r->customer_id,
            'customer_name' => $r->customer_name,
            'industry_code' => $r->industry_code,
            'industry_name' => $industryNames[$r->industry_code] ?? '',
            'product_id' => $r->product_id,
            'product_name' => $r->product_name,
            'contract_id' => $r->contract_id,
            'contract_number' => $r->contract_number,
            'contract_kind' => $r->contract_kind,
            'contract_status' => $r->contract_status,
            'start_date' => $r->start_date,
            'end_date' => $r->end_date,
        ])->all();
    }

    /** @return Collection<string, string> */
    public static function customerNames(string $companyId)
    {
        return CompanyIndividual::where('company_id', $companyId)->pluck('name', 'id');
    }

    // ---- Accounting Reports --------------------------------------
    //
    // AR and AP aging are deliberately NOT re-implemented here.
    // Python's reports.py copies both bucketing loops into this
    // module; `backend-php` already has them as shared services
    // (App\Services\AccountsReceivableService::agingRows and
    // App\Services\PayablesService::agingRows), which the AR and AP
    // screens call, so the Accounting Reports screen calls the same
    // ones -- the two screens can then never disagree about what a
    // customer owes, which is the property Python's own comment says
    // the copy is there to preserve.

    /**
     * GST Return (analysis only). Mirrors `gst_return_data`.
     *
     * Output tax comes from sales invoices broken out per tax code;
     * input tax from supplier bills as a single total, because a
     * SupplierInvoice carries no tax code of its own. The tax point is
     * the invoice date. This files nothing with IRAS and posts nothing
     * to the GL -- it is a read. Bad-debt relief on written-off
     * invoices is a separate IRAS scheme this does not attempt.
     *
     * @return array<string, mixed>
     */
    public static function gstReturn(string $companyId, Carbon $periodStart, Carbon $periodEnd): array
    {
        $outputByCode = [];
        $invoices = Invoice::where('company_id', $companyId)
            ->whereDate('issued_at', '>=', $periodStart->toDateString())
            ->whereDate('issued_at', '<=', $periodEnd->toDateString())
            ->get();
        foreach ($invoices as $invoice) {
            $code = (string) $invoice->tax_code;
            $row = $outputByCode[$code] ?? ['net' => Money::of(0), 'tax' => Money::of(0), 'count' => 0];
            $outputByCode[$code] = [
                'net' => $row['net']->plus(Money::of($invoice->amount_sgd ?? 0)),
                'tax' => $row['tax']->plus(Money::of($invoice->gst_amount_sgd ?? 0)),
                'count' => $row['count'] + 1,
            ];
        }

        $bills = SupplierInvoice::where('company_id', $companyId)
            ->whereDate('invoice_date', '>=', $periodStart->toDateString())
            ->whereDate('invoice_date', '<=', $periodEnd->toDateString())
            ->get();
        $inputNet = Money::of(0);
        $inputTax = Money::of(0);
        foreach ($bills as $bill) {
            $inputNet = $inputNet->plus(Money::of($bill->amount_sgd ?? 0));
            $inputTax = $inputTax->plus(Money::of($bill->gst_amount_sgd ?? 0));
        }

        $outputRows = [];
        foreach ($outputByCode as $code => $row) {
            $outputRows[] = [
                'tax_code' => $code,
                'net_sgd' => $row['net']->toFloat(),
                'tax_sgd' => $row['tax']->toFloat(),
                'document_count' => $row['count'],
            ];
        }
        $inputRows = $bills->isEmpty() ? [] : [[
            'tax_code' => 'PURCHASES',
            'net_sgd' => $inputNet->toFloat(),
            'tax_sgd' => $inputTax->toFloat(),
            'document_count' => $bills->count(),
        ]];

        $totalOutput = Money::of(0);
        foreach ($outputByCode as $row) {
            $totalOutput = $totalOutput->plus($row['tax']);
        }

        return [
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'output_rows' => $outputRows,
            'input_rows' => $inputRows,
            'total_output_tax_sgd' => $totalOutput->toFloat(),
            'total_input_tax_sgd' => $inputTax->toFloat(),
            // Negative means reclaimable.
            'net_gst_payable_sgd' => $totalOutput->minus($inputTax)->toFloat(),
        ];
    }

    /**
     * Sales Gross Profit, one row per invoice issued in the range.
     * Mirrors `sales_gp_rows`.
     *
     * A null cost_sgd is treated as zero cost, not as unknown -- so
     * such an invoice reads as 100% GP. `has_cost_basis` is what tells
     * the reader which rows those are; it is not a figure to quietly
     * exclude.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function salesGpRows(string $companyId, Carbon $periodStart, Carbon $periodEnd): array
    {
        $invoices = Invoice::where('company_id', $companyId)
            ->whereDate('issued_at', '>=', $periodStart->toDateString())
            ->whereDate('issued_at', '<=', $periodEnd->toDateString())
            ->orderBy('issued_at')
            ->get();

        return $invoices->map(function (Invoice $inv) {
            $revenue = Money::of($inv->amount_sgd ?? 0);
            $cost = Money::of($inv->cost_sgd ?? 0);
            $gp = $revenue->minus($cost);

            return [
                'invoice_id' => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'issued_at' => $inv->issued_at,
                'customer_id' => $inv->customer_id,
                'revenue_sgd' => $revenue->toFloat(),
                'cost_sgd' => $cost->toFloat(),
                'gp_sgd' => $gp->toFloat(),
                'gp_percent' => $revenue->toFloat() == 0.0
                    ? 0.0
                    : $gp->dividedBy($revenue->toString())->multipliedBy(100)->toFloat(),
                'has_cost_basis' => $inv->cost_sgd !== null,
            ];
        })->all();
    }

    /**
     * The admin-set commission rate. Zero until one is set -- never
     * invented (docs/open-business-decisions.md #34).
     */
    public static function commissionRatePercent(string $companyId): string
    {
        $settings = CommissionSettings::find($companyId);

        return (string) ($settings?->rate_percent ?? '0.00');
    }

    /**
     * Commission = rate% x gross profit, prorated by how much of the
     * invoice a receipt actually settled, one row per (salesperson,
     * month). Mirrors `commission_rows`.
     *
     * Confirmed 2026-09-12: a flat percentage of gross profit,
     * triggered by receipt allocation rather than by invoicing. The
     * salesperson credited is the invoice's own contract's
     * `sales_staff_id` -- an existing first-class field, not something
     * invented here -- and an excess-usage invoice credits its
     * contract the same way. The month is the receipt's own payment
     * date.
     *
     * An allocation is against the invoice TOTAL, which includes GST,
     * so it is first converted to its share of the invoice's net
     * revenue: GST never inflates commission.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function commissionRows(string $companyId, Carbon $periodStart, Carbon $periodEnd): array
    {
        $rate = Money::of(self::commissionRatePercent($companyId));
        $allocations = PaymentAllocation::with(['payment', 'invoice'])
            ->where('company_id', $companyId)
            ->get();

        $totals = [];
        foreach ($allocations as $alloc) {
            $payment = $alloc->payment;
            $invoice = $alloc->invoice;
            if ($payment === null || $invoice === null) {
                continue;
            }
            if ($payment->payment_date->lt($periodStart) || $payment->payment_date->gt($periodEnd)) {
                continue;
            }
            $total = Money::of($invoice->total_amount_sgd ?? 0);
            if ($total->toFloat() == 0.0) {
                continue;
            }
            $contract = $invoice->contract_id !== null ? Contract::find($invoice->contract_id) : null;
            $salesStaffId = $contract?->sales_staff_id;

            $netShare = Money::of($alloc->amount_sgd)
                ->multipliedByMoney(Money::of($invoice->amount_sgd ?? 0))
                ->dividedBy($total->toString());
            $gpRatio = self::invoiceGpPercent($invoice)->dividedBy(100);
            $commission = $netShare->multipliedByMoney($gpRatio)
                ->multipliedByMoney($rate)->dividedBy(100)->quantize();

            $key = $payment->payment_date->format('Y-m').'|'.($salesStaffId ?? '');
            $totals[$key] = isset($totals[$key]) ? $totals[$key]->plus($commission) : $commission;
        }

        ksort($totals);
        $rows = [];
        foreach ($totals as $key => $amount) {
            [$month, $staffId] = explode('|', $key, 2);
            $rows[] = [
                'month' => $month,
                'sales_staff_id' => $staffId === '' ? null : $staffId,
                'commission_sgd' => $amount->toFloat(),
            ];
        }

        return $rows;
    }

    /**
     * An invoice's gross profit as a percentage of its net revenue.
     * Mirrors the `gp_percent` property on Python's Invoice model,
     * which `backend-php`'s Invoice has no equivalent of; kept here,
     * beside the only two reports that need it, rather than widening
     * the model for one report family.
     */
    private static function invoiceGpPercent(Invoice $invoice): Money
    {
        $revenue = Money::of($invoice->amount_sgd ?? 0);
        if ($revenue->toFloat() == 0.0) {
            return Money::of(0);
        }
        $gp = $revenue->minus(Money::of($invoice->cost_sgd ?? 0));

        return $gp->dividedBy($revenue->toString())->multipliedBy(100)->quantize();
    }

    /**
     * Names for whichever staff actually appear in a report's rows.
     *
     * Deliberately keyed off the ids in the result set rather than off
     * a company: Python's own `_user_names` loads every user for the
     * same reason -- a staff member reaches a company through
     * UserCompanyAccess, so filtering the `users` table by its own
     * company column would blank out the name of anyone whose home
     * company differs from the one being reported on.
     *
     * @param  iterable<int, string|null>  $userIds
     * @return Collection<string, string>
     */
    public static function userNames(iterable $userIds)
    {
        $ids = collect($userIds)->filter()->unique()->values();

        return $ids->isEmpty()
            ? collect()
            : User::whereIn('id', $ids)->pluck('full_name', 'id');
    }

    /** @return Collection<string, string> */
    public static function productNames(string $companyId)
    {
        return Product::where('company_id', $companyId)->pluck('name', 'id');
    }
}
