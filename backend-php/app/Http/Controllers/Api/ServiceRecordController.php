<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Exceptions\ContractRuleViolation;
use App\Http\Controllers\Api\Concerns\SendsDocuments;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\JobOrder;
use App\Models\ServiceRecord;
use App\Models\User;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\DocxForms;
use App\Services\ServiceRecordService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Service Records (formerly "Timesheets"). Mirrors
 * backend/app/routers/service_records.py -- see
 * App\Services\ServiceRecordService for the SRV-003/004/007/015
 * business logic this only orchestrates.
 *
 * NOT yet converted from the Python router (tracked in
 * docs/php-conversion-plan.md): CSV/Excel export. (The .docx export
 * and "Email Service Record" endpoints WERE the other gap here; both
 * are converted now -- see exportDocx()/email() below.)
 */
class ServiceRecordController extends Controller
{
    use SendsDocuments;

    private const MODULE = 'service_records';

    private function recordOrFail(string $companyId, string $recordId): ServiceRecord
    {
        $record = ServiceRecord::find($recordId);
        if (! $record || $record->company_id !== $companyId) {
            throw new ApiException(404, 'Service record not found');
        }

        return $record;
    }

    private function present(ServiceRecord $record): array
    {
        return [
            'id' => $record->id,
            'service_record_number' => $record->service_record_number,
            'job_order_id' => $record->job_order_id,
            'employee_user_id' => $record->employee_user_id,
            'work_date' => optional($record->work_date)->toDateString(),
            'raw_minutes' => $record->raw_minutes,
            'rounded_minutes' => $record->rounded_minutes,
            'deducted_minutes' => $record->deducted_minutes,
            'status' => $record->status,
            'outcome' => $record->outcome,
            'completion_status' => $record->completion_status,
            'is_after_hours' => $record->is_after_hours,
            'is_late' => $record->isLate(),
            'work_description' => $record->work_description,
        ];
    }

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $data = $request->validate([
            'job_order_id' => 'required|uuid',
            'employee_user_id' => 'required|uuid',
            'work_date' => 'required|date',
            'raw_minutes' => 'required|integer|gt:0',
            'completion_status' => 'sometimes|in:C,U',
            'is_after_hours' => 'sometimes|boolean',
            'work_description' => 'sometimes|nullable|string',
        ]);

        try {
            $record = DB::transaction(fn () => ServiceRecordService::submitServiceRecord(
                jobOrderId: $data['job_order_id'],
                employeeUserId: $data['employee_user_id'],
                workDate: $data['work_date'],
                rawMinutes: $data['raw_minutes'],
                actorUserId: $user->id,
                completionStatus: $data['completion_status'] ?? ServiceRecord::UNCOMPLETED,
                isAfterHours: $data['is_after_hours'] ?? false,
                workDescription: $data['work_description'] ?? null,
            ));
        } catch (ContractRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->present($record->fresh()));
    }

    /**
     * Feeds the Service Record Approval page -- every Submitted record
     * across all Job Orders, oldest first, with everything the
     * approver needs to key in a deduction without looking each thing
     * up separately.
     */
    public function pendingApproval(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $records = ServiceRecord::where('company_id', $user->company_id)
            ->where('status', ServiceRecord::STATUS_SUBMITTED)
            ->orderBy('submitted_at')
            ->get();

        if ($records->isEmpty()) {
            return response()->json([]);
        }

        $jobOrders = JobOrder::with('contract')->whereIn('id', $records->pluck('job_order_id')->unique())->get()->keyBy('id');
        $employeeNames = User::whereIn('id', $records->pluck('employee_user_id')->unique())->pluck('full_name', 'id');

        return $records->map(function (ServiceRecord $r) use ($jobOrders, $employeeNames) {
            $jo = $jobOrders->get($r->job_order_id);
            $contract = $jo?->contract;

            return [
                'id' => $r->id,
                'service_record_number' => $r->service_record_number,
                'job_order_id' => $r->job_order_id,
                'job_order_number' => $jo?->job_order_number ?? '',
                'job_order_subject' => $jo?->subject ?? '',
                'is_urgent' => $jo?->is_urgent ?? false,
                'employee_user_id' => $r->employee_user_id,
                'employee_name' => $employeeNames->get($r->employee_user_id, ''),
                'work_date' => optional($r->work_date)->toDateString(),
                'raw_minutes' => $r->raw_minutes,
                'rounded_minutes' => $r->rounded_minutes,
                'completion_status' => $r->completion_status,
                'is_after_hours' => $r->is_after_hours,
                'suggested_deducted_minutes' => ServiceRecordService::suggestedDeductionMinutes(
                    $r->rounded_minutes, $jo?->is_urgent ?? false, $r->is_after_hours,
                ),
                'contract_remaining_minutes' => $contract?->remainingMinutes(),
                'is_late' => $r->isLate(),
            ];
        })->values();
    }

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $query = ServiceRecord::where('company_id', $user->company_id);
        if ($request->filled('job_order_id')) {
            $query->where('job_order_id', $request->query('job_order_id'));
        }
        if ($request->filled('employee_user_id')) {
            $query->where('employee_user_id', $request->query('employee_user_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return $query->orderByDesc('work_date')->get()->map(fn ($r) => $this->present($r))->values();
    }

    public function show(Request $request, string $recordId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return response()->json($this->present($this->recordOrFail($user->company_id, $recordId)));
    }

    public function approve(Request $request, string $recordId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $record = $this->recordOrFail($user->company_id, $recordId);
        $jobOrder = JobOrder::findOrFail($record->job_order_id);
        $data = $request->validate(['deducted_minutes' => 'required|integer|gt:0']);

        try {
            DB::transaction(fn () => ServiceRecordService::approveServiceRecord(
                $record, $jobOrder, $user, $data['deducted_minutes'],
            ));
        } catch (ContractRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->present($record->fresh()));
    }

    /** GET /service-records/{id}/export.docx -- the Word button on the Service Record print page. */
    public function exportDocx(Request $request, string $recordId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $record = $this->recordOrFail($user->company_id, $recordId);
        $jobOrder = JobOrder::find($record->job_order_id);
        $customer = $jobOrder ? CompanyIndividual::find($jobOrder->customer_id) : null;
        $company = Company::find($user->company_id);

        return $this->docxResponse(
            DocxForms::serviceRecordToDocx($record, $jobOrder, $customer, $company),
            "{$record->service_record_number}.docx",
        );
    }

    /**
     * Email Service Record (2026-09-12) -- same real-send pattern as
     * Purchase Order's Email button. Goes to the customer on the Job
     * Order this record was logged against.
     */
    public function email(Request $request, string $recordId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $record = $this->recordOrFail($user->company_id, $recordId);
        $jobOrder = JobOrder::find($record->job_order_id);
        $customer = $jobOrder ? CompanyIndividual::find($jobOrder->customer_id) : null;
        if (! $customer || ! $customer->billing_email) {
            throw new ApiException(422, 'This customer has no email on file -- add one on the Company/Individual page first.');
        }
        $company = Company::find($user->company_id);
        $companyName = $this->companyName($company);
        $docxBytes = DocxForms::serviceRecordToDocx($record, $jobOrder, $customer, $company);
        $body = "Dear {$customer->name},\n\n"
            ."Please find attached Service Record {$record->service_record_number} for Job Order "
            ."{$jobOrder->job_order_number} ({$jobOrder->subject}), dated ".$record->work_date->toDateString().".\n\n"
            ."Regards,\n{$companyName}";

        $result = $this->emailDocument(
            $customer->billing_email,
            "Service Record {$record->service_record_number} - {$companyName}",
            $body,
            $docxBytes,
            $record->service_record_number,
        );

        Audit::record(
            entityType: 'service_record',
            entityId: $record->id,
            action: 'emailed',
            actorUserId: $user->id,
            details: "{$record->service_record_number} emailed to {$customer->billing_email}",
        );

        return response()->json($result);
    }
}
