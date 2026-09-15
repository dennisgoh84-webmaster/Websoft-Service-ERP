<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Contract;
use App\Models\ExcessUsageRecord;
use App\Models\Invoice;
use App\Models\JobOrder;
use App\Models\ServiceRecord;
use App\Services\AccountsReceivableService;
use App\Services\Ledger;
use App\Services\PayablesService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Company Dashboard summary -- the confirmed Service Operations
 * Dashboard requirements from docs/business-requirements.md, as a
 * single aggregated endpoint so the frontend can render one overview
 * screen. Mirrors backend/app/routers/dashboard.py 1:1.
 *
 * Deliberately NOT gated by Module Control: the Python route depends
 * only on `get_current_user`, with no `require_module_access(...)`
 * call, so any authenticated user sees the summary for the company
 * they are currently working in. Kept identical here rather than
 * "tightened", per the conversion's faithful-conversion rule -- the
 * individual tiles all link to screens that are themselves gated, so
 * a user without (say) Accounts Payable access simply cannot follow
 * the AP tile through to any detail.
 *
 * Every figure is scoped to the user's active company (multi-company
 * -- see App\Http\Controllers\Api\CompanyController's switch-company
 * endpoint), matching the Python source's own comment.
 *
 * Every aggregate reuses the service the owning module already
 * exposes rather than re-querying by hand -- AR/AP aging via
 * AccountsReceivableService/PayablesService::agingRows() (Python
 * reads app/services/reports.py's ar_aging_rows/ap_aging_rows, which
 * that file's own docstrings state is "the same bucketing as"
 * those two modules' aging reports) and the trial balance via
 * Ledger::accountBalances(), exactly the call Python's line ~91
 * makes.
 *
 * NO KNOWN GAPS: every module this endpoint aggregates over
 * (Contracts, Job Orders, Service Records, Excess Usage, Billing,
 * Accounts Receivable, Accounts Payable, GL posting) is already
 * converted, so no tile here reports a placeholder figure.
 */
class DashboardController extends Controller
{
    public function summary(Request $request)
    {
        $user = Authenticate::user($request);
        $companyId = $user->company_id;

        $contracts = Contract::where('company_id', $companyId)->get();
        $liveStatuses = [Contract::STATUS_ACTIVE, Contract::STATUS_EXCEEDED];

        $activeContracts = $contracts
            ->filter(fn (Contract $c) => in_array($c->status, $liveStatuses, true))
            ->count();

        $today = Carbon::today();
        $contractsExpiringSoon = $contracts
            ->filter(function (Contract $c) use ($liveStatuses, $today) {
                if (! in_array($c->status, $liveStatuses, true)) {
                    return false;
                }
                // SRV-014: the pre-expiry check window. Python's
                // `(c.end_date - today).days` is a signed whole-day
                // difference, so an already-expired contract (negative)
                // is excluded, same as here.
                $days = $today->diffInDays(Carbon::parse($c->end_date)->startOfDay(), false);

                return $days >= 0 && $days <= Contract::PRE_EXPIRY_CHECK_LEAD_DAYS;
            })
            ->count();

        // Hours, not money -- plain float division by 60, same as
        // Python's `sum(...) / 60`. Money::of() is reserved for the
        // SGD figures further down.
        $totalContracted = $contracts->sum(fn (Contract $c) => $c->contracted_minutes) / 60;
        $totalConsumed = $contracts->sum(fn (Contract $c) => $c->consumed_minutes) / 60;
        $totalRemaining = $contracts->sum(fn (Contract $c) => $c->remainingMinutes()) / 60;

        $excessAwaitingReview = ExcessUsageRecord::where('company_id', $companyId)
            ->whereNull('treatment')
            ->count();

        $openJobOrders = JobOrder::where('company_id', $companyId)
            ->whereIn('status', [JobOrder::STATUS_OPEN, JobOrder::STATUS_ASSIGNED])
            ->count();

        // SRV-015: flagged missing/late if submitted more than 3 business
        // days after the work was performed. (Approximation -- see
        // ServiceRecord::isLate(); detecting *never-submitted* work is
        // future scope.)
        $submittedRecords = ServiceRecord::where('company_id', $companyId)
            ->where('status', ServiceRecord::STATUS_SUBMITTED)
            ->get();
        $missingServiceRecords = $submittedRecords->filter(fn (ServiceRecord $r) => $r->isLate())->count();

        // SRV-019: submitted more than a week ago and still not approved
        // by Nico or Cherish.
        $serviceRecordApprovalsOverdue = $submittedRecords->filter(fn (ServiceRecord $r) => $r->isApprovalOverdue())->count();

        // Every invoice ever issued, not just outstanding ones --
        // "Invoiced to date" on the frontend tile. Python sums the raw
        // `amount_sgd` (net of GST), not `total_amount_sgd`.
        $invoices = Invoice::where('company_id', $companyId)->get();
        $invoicesTotal = Money::of(0);
        foreach ($invoices as $invoice) {
            $invoicesTotal = $invoicesTotal->plus(Money::of($invoice->amount_sgd ?? 0));
        }

        // Financial summary -- same aging/trial-balance calculations as
        // the Accounting Reports screen, just totalled.
        [, $arRows] = AccountsReceivableService::agingRows($companyId);
        $arOutstanding = Money::of(0);
        $arOverdue = Money::of(0);
        foreach ($arRows as $row) {
            $arOutstanding = $arOutstanding->plus(Money::of($row['total']));
            // Overdue = everything except the "current" (not yet due)
            // bucket. An invoice with no due date buckets as current in
            // agingBucketFor(), so it is never invented-overdue.
            $arOverdue = $arOverdue->plus(Money::of($row['total'])->minus(Money::of($row['current'])));
        }

        [, $apRows] = PayablesService::agingRows($companyId);
        $apOutstanding = Money::of(0);
        $apOverdue = Money::of(0);
        foreach ($apRows as $row) {
            $apOutstanding = $apOutstanding->plus(Money::of($row['total']));
            $apOverdue = $apOverdue->plus(Money::of($row['total'])->minus(Money::of($row['current'])));
        }

        $balances = Ledger::accountBalances($companyId);
        $totalDebit = Money::of(0);
        $totalCredit = Money::of(0);
        foreach ($balances as $row) {
            $totalDebit = $totalDebit->plus($row['debit_sgd']);
            $totalCredit = $totalCredit->plus($row['credit_sgd']);
        }
        // Python compares the two totals rounded to 2dp; Money::quantize()
        // is the same 2dp HALF_UP rounding, compared as strings so the
        // comparison itself never goes through a float.
        $glIsBalanced = $totalDebit->quantize()->toString() === $totalCredit->quantize()->toString();

        return response()->json([
            'active_contracts' => $activeContracts,
            'contracts_expiring_soon' => $contractsExpiringSoon,
            'total_contracted_hours' => $totalContracted,
            'total_consumed_hours' => $totalConsumed,
            'total_remaining_hours' => $totalRemaining,
            'excess_awaiting_review' => $excessAwaitingReview,
            'open_job_orders' => $openJobOrders,
            'missing_service_records' => $missingServiceRecords,
            'service_records_awaiting_approval' => $submittedRecords->count(),
            'service_record_approvals_overdue' => $serviceRecordApprovalsOverdue,
            'invoices_total_sgd' => $invoicesTotal->toFloat(),
            'invoices_count' => $invoices->count(),
            'ar_outstanding_sgd' => $arOutstanding->toFloat(),
            'ar_overdue_sgd' => $arOverdue->toFloat(),
            'ap_outstanding_sgd' => $apOutstanding->toFloat(),
            'ap_overdue_sgd' => $apOverdue->toFloat(),
            'gl_is_balanced' => $glIsBalanced,
        ]);
    }
}
