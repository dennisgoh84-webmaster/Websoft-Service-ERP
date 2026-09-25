<?php

namespace App\Services\DataMigration\Importers;

use App\Models\JobOrder;
use App\Models\ServiceRecord;
use App\Services\DataMigration\EntityImporter;
use App\Services\DataMigration\ImportContext;
use App\Services\DataMigration\RowFailed;
use App\Services\DataMigration\SourceRecord;
use App\Services\Numbering;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ODOO Timesheets / ZSOFT Service Records -> Service Record.
 *
 * A Service Record always belongs to a Job Order:
 * - when the row names one (a ZSOFT service record's job order
 *   number, migrated with the Job Orders module), it is filed there;
 * - otherwise (an ODOO timesheet hangs off a project and task, not a
 *   job order) the import opens one CLOSED Job Order per Company /
 *   Individual + project + task the first time it meets one, and later
 *   lines and later uploads reuse it.
 * The old record number is kept when mapped; ODOO timesheets have none,
 * so they draw theirs from this system's own counter.
 *
 * HISTORY ONLY: each record arrives approved, with the old hours
 * exactly as logged (no SRV-007 re-rounding of work already billed),
 * and outcome not_hour_metered -- it does NOT deduct from any contract,
 * because a migrated contract's balance arrives as its Consumed hours,
 * and deducting again would count those hours twice.
 *
 * Staff the old system knew who are not users here -- usually former
 * employees -- become inactive users (decided 2026-09-25).
 */
class ServiceRecordsImporter extends EntityImporter
{
    public function entity(): string
    {
        return 'service_records';
    }

    public function label(): string
    {
        return 'Service Records';
    }

    public function fields(): array
    {
        return self::sourceIdField('If left unmapped, the service record number is used.') + [
            'record_number' => ['label' => 'Service record number', 'aliases' => ['service record no', 'sr no', 'sr_no', 'record no', 'service record number'], 'hint' => 'Blank = numbered here (ODOO timesheets have none).'],
            'date' => ['label' => 'Work date', 'required' => true, 'aliases' => ['date', 'work date', 'service date']],
            'employee_id' => ['label' => 'Staff', 'required' => true, 'aliases' => ['employee_id', 'employee', 'user_id', 'user', 'engineer', 'technician', 'staff']],
            'unit_amount' => ['label' => 'Hours', 'required' => true, 'aliases' => ['unit_amount', 'quantity', 'time spent', 'hours spent', 'duration', 'hours']],
            'name' => ['label' => 'Work description', 'aliases' => ['name', 'description', 'work done', 'remarks']],
            'job_order' => ['label' => 'Job order number', 'aliases' => ['job order no', 'job order number', 'jo no', 'jo_no', 'job no'], 'hint' => 'ZSOFT: the migrated job order it belongs to.'],
        ] + self::partyFields() + [
            'project_id' => ['label' => 'Project', 'aliases' => ['project_id', 'project']],
            'task_id' => ['label' => 'Task', 'aliases' => ['task_id', 'task']],
        ];
    }

    public function targetType(Model $model): string
    {
        return 'service_record';
    }

    public function sourceRef(SourceRecord $record, ImportContext $ctx): ?string
    {
        return $record->row->get('id', 'record_number');
    }

    public function import(SourceRecord $record, ImportContext $ctx): Model
    {
        $row = $record->row;
        $staffName = $row->get('employee_id');
        if ($staffName === null) {
            throw new RowFailed('Staff is required.');
        }

        $hours = $row->get('unit_amount');
        if ($hours === null || ! is_numeric($hours) || (float) $hours <= 0) {
            throw new RowFailed('Hours must be a number greater than zero.');
        }
        $minutes = (int) round((float) $hours * 60);
        $workDate = $ctx->date($row->get('date'), 'Work date');

        $number = $row->get('record_number');
        if ($number !== null) {
            $ctx->requireUnusedNumber(ServiceRecord::class, 'service_record_number', $number);
        }

        if (($jobOrderNumber = $row->get('job_order')) !== null) {
            $jobOrder = JobOrder::where('company_id', $ctx->company->id)->where('job_order_number', $jobOrderNumber)->first();
            if ($jobOrder === null) {
                throw new RowFailed("Job Order {$jobOrderNumber} is not here -- import Job Orders first.");
            }
        } else {
            $customer = $ctx->customer($row);
            $jobOrder = $this->holdingJobOrder($row->get('project_id'), $row->get('task_id'), $customer->id, $workDate, $ctx);
        }

        $employee = $ctx->staff($staffName);

        return ServiceRecord::create([
            'company_id' => $ctx->company->id,
            'job_order_id' => $jobOrder->id,
            'employee_user_id' => $employee->id,
            'service_record_number' => $number ?? Numbering::next($ctx->company->id, 'service_record', $workDate),
            'work_date' => $workDate->toDateString(),
            'raw_minutes' => $minutes,
            'rounded_minutes' => $minutes,
            'status' => ServiceRecord::STATUS_APPROVED,
            'outcome' => ServiceRecord::OUTCOME_NOT_HOUR_METERED,
            'completion_status' => ServiceRecord::COMPLETED,
            'work_description' => $row->get('name'),
            'submitted_at' => $workDate,
            'approved_at' => $workDate,
        ]);
    }

    private function holdingJobOrder(?string $project, ?string $task, string $customerId, Carbon $workDate, ImportContext $ctx): JobOrder
    {
        $key = implode('|', ["customer:{$customerId}", 'project:'.($project ?? ''), 'task:'.($task ?? '')]);
        $map = $ctx->mapped('timesheet_job_orders', $key);
        if ($map !== null) {
            return JobOrder::findOrFail($map->target_id);
        }

        $subject = trim(implode(' / ', array_filter([$project, $task])));
        $jobOrder = JobOrder::create([
            'company_id' => $ctx->company->id,
            'customer_id' => $customerId,
            'job_order_number' => Numbering::next($ctx->company->id, 'job_order', $workDate),
            'subject' => mb_substr($subject !== '' ? "{$ctx->sourceLabel()}: {$subject}" : "{$ctx->sourceLabel()} timesheets", 0, 255),
            'job_order_type' => JobOrder::TYPE_SUPPORT,
            'status' => JobOrder::STATUS_CLOSED,
            'closed_at' => Carbon::now(),
        ]);
        $ctx->remember('timesheet_job_orders', $key, 'job_order', $jobOrder);
        $ctx->warn("Opened closed Job Order {$jobOrder->job_order_number} \"{$jobOrder->subject}\" to hold these migrated records.");

        return $jobOrder;
    }

    public function describe(Model $model): string
    {
        return "Service Record {$model->service_record_number}, {$model->rounded_minutes} min";
    }
}
