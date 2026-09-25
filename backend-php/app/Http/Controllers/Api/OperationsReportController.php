<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ScopesReportCompanies;
use App\Http\Controllers\Api\Concerns\SendsExports;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Contract;
use App\Models\GroupModuleAuthority;
use App\Models\JobOrder;
use App\Models\Product;
use App\Models\ServiceRecord;
use App\Models\User;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\ReportsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Operations Reports. Mirrors the `/reports/operations/*` half of
 * backend/app/routers/reports.py.
 *
 * Four reports -- Contracts, Job Orders, Service Records, and Customer
 * Product Usage -- each with the same filters the screen offers plus
 * CSV and Excel export. Gated on `operations_reports` at VIEW: these
 * are read-only views over data other modules own, so a manager can be
 * given the reports without being given the ability to change anything
 * they report on.
 *
 * Every report can cover one or several of the viewer's own companies
 * (`company_ids`, 2026-09-25); rows then carry company_id / company_name
 * and the exports gain an Internal Company column.
 *
 * EVERY EXPORT IS AUDITED with the report name, format and row count,
 * matching Python's `_audit_export`. Reading a whole module's data in
 * one file is worth recording even though reading a single record is
 * not.
 */
class OperationsReportController extends Controller
{
    use ScopesReportCompanies;
    use SendsExports;

    private const MODULE = 'operations_reports';

    /**
     * Python audits this one as "Operations Report: CompanyIndividual
     * Product Usage" -- a leftover from the Customer ->
     * Company/Individual rename that ran through that codebase. The
     * screen label is used here instead, per docs/ui-guidelines.md;
     * the audit detail line is read by people, not parsed.
     */
    private const USAGE_REPORT_NAME = 'Operations Report: Company / Individual Product Usage';

    /** @var array<int, string> */
    private const CONTRACT_FIELDS = [
        'contract_number', 'customer_name', 'status', 'contract_kind', 'contracted_hours',
        'consumed_hours', 'remaining_hours', 'contract_value_sgd', 'start_date', 'end_date',
    ];

    /** @var array<int, string> */
    private const JOB_ORDER_FIELDS = [
        'job_order_number', 'customer_name', 'subject', 'priority', 'status', 'assigned_to',
        'due_date', 'overdue', 'created_at',
    ];

    /** @var array<int, string> */
    private const SERVICE_RECORD_FIELDS = [
        'service_record_number', 'work_date', 'customer_name', 'employee', 'hours', 'status',
        'outcome', 'is_late',
    ];

    /** @var array<int, string> */
    private const USAGE_FIELDS = [
        'customer_name', 'industry_name', 'product_name', 'contract_number', 'contract_kind',
        'contract_status', 'start_date', 'end_date',
    ];

    // ── Contracts ───────────────────────────────────────────────────

    public function contracts(Request $request)
    {
        $user = $this->viewer($request);
        $scope = $this->scope($request, $user);
        $names = ReportsService::customerNames(array_keys($scope));

        return response()->json(
            $this->filteredContracts(array_keys($scope), $request)
                ->map(fn (Contract $c) => $this->presentContract($c, $scope, $names))->all()
        );
    }

    public function contractsCsv(Request $request)
    {
        $user = $this->viewer($request);
        $scope = $this->scope($request, $user);
        $rows = $this->contractRows($scope, $request);
        $this->auditExport($user, 'Operations Report: Contracts', 'csv', count($rows));

        return $this->csvResponse($this->withScopeColumn(self::CONTRACT_FIELDS, $scope), $rows, 'contracts-report.csv');
    }

    public function contractsExcel(Request $request)
    {
        $user = $this->viewer($request);
        $scope = $this->scope($request, $user);
        $rows = $this->contractRows($scope, $request);
        $this->auditExport($user, 'Operations Report: Contracts', 'excel', count($rows));

        return $this->xlsxResponse($this->withScopeColumn(self::CONTRACT_FIELDS, $scope), $rows, 'Contracts', 'contracts-report.xlsx');
    }

    /**
     * One or several Company / Individual ids: `customer_ids` (comma-
     * separated, 2026-09-24) or the original single `customer_id`.
     *
     * @return array<int, string>|null
     */
    private function customerIds(Request $request): ?array
    {
        $raw = $request->query('customer_ids', $request->query('customer_id'));
        $ids = array_values(array_filter(array_map('trim', explode(',', (string) $raw)), fn ($v) => $v !== ''));

        return $ids === [] ? null : $ids;
    }

    /**
     * @param  array<int, string>  $companyIds
     * @return Collection<int, Contract>
     */
    private function filteredContracts(array $companyIds, Request $request)
    {
        return ReportsService::contracts(
            $companyIds,
            $request->query('status'),
            $request->query('contract_kind'),
            $this->customerIds($request),
            $request->filled('expiring_within_days') ? (int) $request->query('expiring_within_days') : null,
            $this->date($request, 'start_date'),
            $this->date($request, 'end_date'),
        );
    }

    /**
     * The record shape the Contracts screen already uses, mirroring
     * Python's `response_model=list[ContractOut]` -- hours as numbers,
     * not the formatted strings the export rows carry.
     *
     * @return array<string, mixed>
     */
    private function presentContract(Contract $c, array $scope, $names): array
    {
        return [
            'id' => $c->id,
            ...$this->companyFields($c->company_id, $scope),
            'contract_number' => $c->contract_number,
            'customer_id' => $c->customer_id,
            'customer_name' => $names[$c->customer_id] ?? '',
            'status' => $c->status,
            'contract_kind' => $c->contract_kind,
            'contracted_hours' => $c->contracted_minutes / 60,
            'consumed_hours' => $c->consumed_minutes / 60,
            'remaining_hours' => $c->remainingMinutes() / 60,
            'contract_value_sgd' => (float) $c->contract_value_sgd,
            'hourly_rate_sgd' => $c->hourly_rate_sgd !== null ? (float) $c->hourly_rate_sgd : null,
            'sales_staff_id' => $c->sales_staff_id,
            'start_date' => optional($c->start_date)->toDateString(),
            'end_date' => optional($c->end_date)->toDateString(),
            'renewed_from_contract_id' => $c->renewed_from_contract_id,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function contractRows(array $scope, Request $request): array
    {
        $names = ReportsService::customerNames(array_keys($scope));

        return $this->filteredContracts(array_keys($scope), $request)->map(fn (Contract $c) => [
            'company_name' => $scope[$c->company_id] ?? '',
            'contract_number' => $c->contract_number,
            'customer_name' => $names[$c->customer_id] ?? '',
            'status' => $c->status,
            'contract_kind' => $c->contract_kind,
            // Hours, not minutes, at 2dp -- the export is read by people.
            'contracted_hours' => number_format($c->contracted_minutes / 60, 2, '.', ''),
            'consumed_hours' => number_format($c->consumed_minutes / 60, 2, '.', ''),
            'remaining_hours' => number_format($c->remainingMinutes() / 60, 2, '.', ''),
            'contract_value_sgd' => number_format((float) $c->contract_value_sgd, 2, '.', ''),
            'start_date' => optional($c->start_date)->toDateString(),
            'end_date' => optional($c->end_date)->toDateString(),
        ])->all();
    }

    // ── Job Orders ──────────────────────────────────────────────────

    public function jobOrders(Request $request)
    {
        $user = $this->viewer($request);
        $scope = $this->scope($request, $user);
        $orders = $this->filteredJobOrders(array_keys($scope), $request);
        $customers = ReportsService::customerNames(array_keys($scope));
        $users = ReportsService::userNames($orders->pluck('assigned_to_user_id'));

        return response()->json($orders->map(fn (JobOrder $o) => $this->presentJobOrder($o, $scope, $customers, $users))->all());
    }

    public function jobOrdersCsv(Request $request)
    {
        $user = $this->viewer($request);
        $scope = $this->scope($request, $user);
        $rows = $this->jobOrderRows($scope, $request);
        $this->auditExport($user, 'Operations Report: Job Orders', 'csv', count($rows));

        return $this->csvResponse($this->withScopeColumn(self::JOB_ORDER_FIELDS, $scope), $rows, 'job-orders-report.csv');
    }

    public function jobOrdersExcel(Request $request)
    {
        $user = $this->viewer($request);
        $scope = $this->scope($request, $user);
        $rows = $this->jobOrderRows($scope, $request);
        $this->auditExport($user, 'Operations Report: Job Orders', 'excel', count($rows));

        return $this->xlsxResponse($this->withScopeColumn(self::JOB_ORDER_FIELDS, $scope), $rows, 'Job Orders', 'job-orders-report.xlsx');
    }

    /**
     * @param  array<int, string>  $companyIds
     * @return Collection<int, JobOrder>
     */
    private function filteredJobOrders(array $companyIds, Request $request)
    {
        return ReportsService::jobOrders(
            $companyIds,
            $request->query('status'),
            $this->customerIds($request),
            $request->query('assigned_to_user_id'),
            $request->boolean('overdue_only'),
            $this->date($request, 'start_date'),
            $this->date($request, 'end_date'),
        );
    }

    /**
     * The record shape the Job Orders screen already uses, mirroring
     * Python's `response_model=list[JobOrderOut]`. The report screen
     * works out "overdue" itself from due_date + status, so the flag
     * the export rows carry is deliberately not part of this shape.
     *
     * @return array<string, mixed>
     */
    private function presentJobOrder(JobOrder $o, array $scope, $customers, $users): array
    {
        return [
            'id' => $o->id,
            ...$this->companyFields($o->company_id, $scope),
            'job_order_number' => $o->job_order_number,
            'customer_id' => $o->customer_id,
            'customer_name' => $customers[$o->customer_id] ?? '',
            'assigned_to_name' => $o->assigned_to_user_id === null ? null : ($users[$o->assigned_to_user_id] ?? null),
            'contract_id' => $o->contract_id,
            'subject' => $o->subject,
            'job_order_type' => $o->job_order_type,
            'priority' => $o->priority,
            'status' => $o->status,
            'is_urgent' => $o->is_urgent,
            'assigned_to_user_id' => $o->assigned_to_user_id,
            'due_date' => optional($o->due_date)->toDateString(),
            'void_reason' => $o->void_reason,
            'budget_overrun_approved' => $o->budget_overrun_approved,
            'budget_overrun_approved_by' => $o->budget_overrun_approved_by,
            'budget_overrun_approved_at' => $o->budget_overrun_approved_at,
            'created_at' => $o->created_at,
            'closed_at' => $o->closed_at,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function jobOrderRows(array $scope, Request $request): array
    {
        $orders = $this->filteredJobOrders(array_keys($scope), $request);
        $customers = ReportsService::customerNames(array_keys($scope));
        $users = ReportsService::userNames($orders->pluck('assigned_to_user_id'));
        $today = Carbon::today();

        return $orders->map(fn (JobOrder $o) => [
            'company_name' => $scope[$o->company_id] ?? '',
            'job_order_number' => $o->job_order_number,
            'customer_name' => $customers[$o->customer_id] ?? '',
            'subject' => $o->subject,
            'priority' => $o->priority,
            'status' => $o->status,
            'assigned_to' => $o->assigned_to_user_id === null ? '' : ($users[$o->assigned_to_user_id] ?? ''),
            'due_date' => optional($o->due_date)->toDateString() ?? '',
            // NOTE, carried across verbatim: Python tests the status
            // against the strings "resolved" and "closed", but this
            // system's JobOrderStatus has no "resolved" state -- so in
            // practice only "closed" excludes a job order from being
            // called overdue, and a VOID one still reads as overdue.
            // Preserved rather than corrected; recorded as a finding in
            // docs/php-conversion-plan.md.
            'overdue' => ($o->due_date !== null && $o->due_date->lt($today)
                && ! in_array($o->status, ['resolved', 'closed'], true)) ? 'Yes' : 'No',
            'created_at' => optional($o->created_at)->toDateString(),
        ])->all();
    }

    // ── Service Records ─────────────────────────────────────────────

    public function serviceRecords(Request $request)
    {
        $user = $this->viewer($request);
        $scope = $this->scope($request, $user);
        $records = $this->filteredServiceRecords(array_keys($scope), $request);
        $users = ReportsService::userNames($records->pluck('employee_user_id'));
        $customers = ReportsService::customerNames(array_keys($scope));
        $jobOrderCustomers = JobOrder::whereIn('company_id', array_keys($scope))->pluck('customer_id', 'id');

        return response()->json($records->map(function (ServiceRecord $r) use ($scope, $users, $customers, $jobOrderCustomers) {
            $customerId = $jobOrderCustomers[$r->job_order_id] ?? null;

            return [
                ...$this->presentServiceRecord($r),
                ...$this->companyFields($r->company_id, $scope),
                'customer_id' => $customerId,
                'customer_name' => $customerId === null ? '' : ($customers[$customerId] ?? ''),
                'employee_name' => $users[$r->employee_user_id] ?? '',
            ];
        })->all());
    }

    public function serviceRecordsCsv(Request $request)
    {
        $user = $this->viewer($request);
        $scope = $this->scope($request, $user);
        $rows = $this->serviceRecordRows($scope, $request);
        $this->auditExport($user, 'Operations Report: Service Records', 'csv', count($rows));

        return $this->csvResponse($this->withScopeColumn(self::SERVICE_RECORD_FIELDS, $scope), $rows, 'service-records-report.csv');
    }

    public function serviceRecordsExcel(Request $request)
    {
        $user = $this->viewer($request);
        $scope = $this->scope($request, $user);
        $rows = $this->serviceRecordRows($scope, $request);
        $this->auditExport($user, 'Operations Report: Service Records', 'excel', count($rows));

        return $this->xlsxResponse($this->withScopeColumn(self::SERVICE_RECORD_FIELDS, $scope), $rows, 'Service Records', 'service-records-report.xlsx');
    }

    /**
     * @param  array<int, string>  $companyIds
     * @return Collection<int, ServiceRecord>
     */
    private function filteredServiceRecords(array $companyIds, Request $request)
    {
        return ReportsService::serviceRecords(
            $companyIds,
            $request->query('status'),
            $request->query('outcome'),
            $this->customerIds($request),
            $request->query('employee_user_id'),
            $this->date($request, 'start_date'),
            $this->date($request, 'end_date'),
        );
    }

    /**
     * The record shape the Service Records screen already uses,
     * mirroring Python's `response_model=list[ServiceRecordOut]` --
     * minutes, which the screen divides itself, not the formatted
     * hours the export rows carry.
     *
     * @return array<string, mixed>
     */
    private function presentServiceRecord(ServiceRecord $r): array
    {
        return [
            'id' => $r->id,
            'service_record_number' => $r->service_record_number,
            'job_order_id' => $r->job_order_id,
            'employee_user_id' => $r->employee_user_id,
            'work_date' => optional($r->work_date)->toDateString(),
            'raw_minutes' => $r->raw_minutes,
            'rounded_minutes' => $r->rounded_minutes,
            'deducted_minutes' => $r->deducted_minutes,
            'status' => $r->status,
            'outcome' => $r->outcome,
            'completion_status' => $r->completion_status,
            'is_after_hours' => $r->is_after_hours,
            'is_late' => $r->isLate(),
            'work_description' => $r->work_description,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function serviceRecordRows(array $scope, Request $request): array
    {
        $records = $this->filteredServiceRecords(array_keys($scope), $request);
        $users = ReportsService::userNames($records->pluck('employee_user_id'));
        $customers = ReportsService::customerNames(array_keys($scope));
        // A Service Record reaches its customer through its Job Order.
        $jobOrderCustomers = JobOrder::whereIn('company_id', array_keys($scope))->pluck('customer_id', 'id');

        return $records->map(fn (ServiceRecord $r) => [
            'company_name' => $scope[$r->company_id] ?? '',
            'service_record_number' => $r->service_record_number,
            'work_date' => optional($r->work_date)->toDateString(),
            'customer_name' => $customers[$jobOrderCustomers[$r->job_order_id] ?? ''] ?? '',
            'employee' => $users[$r->employee_user_id] ?? '',
            'hours' => number_format($r->rounded_minutes / 60, 2, '.', ''),
            'status' => $r->status,
            'outcome' => $r->outcome,
            'is_late' => $r->isLate() ? 'Yes' : 'No',
        ])->all();
    }

    // ── Customer Product Usage ──────────────────────────────────────

    public function customerProductUsage(Request $request)
    {
        $user = $this->viewer($request);

        return response()->json($this->usageRows($this->scope($request, $user), $request));
    }

    public function customerProductUsageCsv(Request $request)
    {
        $user = $this->viewer($request);
        $scope = $this->scope($request, $user);
        $rows = $this->usageExportRows($scope, $request);
        $this->auditExport($user, self::USAGE_REPORT_NAME, 'csv', count($rows));

        return $this->csvResponse($this->withScopeColumn(self::USAGE_FIELDS, $scope), $rows, 'customer-product-usage.csv');
    }

    public function customerProductUsageExcel(Request $request)
    {
        $user = $this->viewer($request);
        $scope = $this->scope($request, $user);
        $rows = $this->usageExportRows($scope, $request);
        $this->auditExport($user, self::USAGE_REPORT_NAME, 'excel', count($rows));

        return $this->xlsxResponse($this->withScopeColumn(self::USAGE_FIELDS, $scope), $rows, 'Company Individual Product Usage', 'customer-product-usage.xlsx');
    }

    /** @return array<int, array<string, mixed>> */
    private function usageRows(array $scope, Request $request): array
    {
        return array_map(fn (array $r) => [
            ...$r,
            'company_name' => $scope[$r['company_id']] ?? '',
            'start_date' => $r['start_date'] instanceof Carbon ? $r['start_date']->toDateString() : $r['start_date'],
            'end_date' => $r['end_date'] instanceof Carbon ? $r['end_date']->toDateString() : $r['end_date'],
        ], ReportsService::customerProductUsage(
            array_keys($scope),
            $this->customerIds($request),
            $request->query('product_id'),
            $request->query('industry_code'),
        ));
    }

    /** @return array<int, array<string, mixed>> */
    private function usageExportRows(array $scope, Request $request): array
    {
        return array_map(fn (array $r) => [
            'company_name' => $r['company_name'],
            'customer_name' => $r['customer_name'],
            'industry_name' => $r['industry_name'],
            'product_name' => $r['product_name'],
            'contract_number' => $r['contract_number'],
            'contract_kind' => $r['contract_kind'],
            'contract_status' => $r['contract_status'],
            'start_date' => $r['start_date'],
            'end_date' => $r['end_date'],
        ], $this->usageRows($scope, $request));
    }

    // ── Filter choices ──────────────────────────────────────────────

    /**
     * Company / Individual, staff and product choices across the ticked
     * internal companies (labelled with the company code when there are
     * several).
     */
    public function filterOptions(Request $request)
    {
        $user = $this->viewer($request);
        $scope = $this->scope($request, $user);
        $ids = array_keys($scope);

        return response()->json([
            'company_individuals' => $this->scopedCompanyIndividuals($scope),
            'staff' => User::whereIn('company_id', $ids)->where('is_active', true)->orderBy('full_name')->get(['id', 'full_name', 'company_id'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $this->scopedLabel($scope, $u->full_name, $u->company_id)])->values(),
            'products' => Product::whereIn('company_id', $ids)->orderBy('name')->get(['id', 'name', 'company_id'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $this->scopedLabel($scope, $p->name, $p->company_id)])->values(),
        ]);
    }

    // ── Shared ──────────────────────────────────────────────────────

    /** @return array<string, string> */
    private function scope(Request $request, User $user): array
    {
        return $this->reportCompanyScope($request, $user, self::MODULE, 'Operations Reports');
    }

    /** @return array{company_id: string, company_name: string} */
    private function companyFields(string $companyId, array $scope): array
    {
        return ['company_id' => $companyId, 'company_name' => $scope[$companyId] ?? ''];
    }

    private function viewer(Request $request): User
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::VIEW);

        return $user;
    }

    private function date(Request $request, string $key): ?Carbon
    {
        return $request->filled($key) ? Carbon::parse($request->query($key)) : null;
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
