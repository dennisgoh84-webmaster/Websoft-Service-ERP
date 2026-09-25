<?php

namespace App\Services\OdooMigration\Importers;

use App\Models\JobOrder;
use App\Models\ServiceRecord;
use App\Services\Numbering;
use App\Services\OdooMigration\EntityImporter;
use App\Services\OdooMigration\ImportContext;
use App\Services\OdooMigration\OdooRecord;
use App\Services\OdooMigration\RowFailed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Odoo Timesheets (account.analytic.line) -> Service Record.
 *
 * A Service Record always belongs to a Job Order, and Odoo has no Job
 * Order: a timesheet line hangs off a project and task instead. So the
 * import opens one CLOSED Job Order per customer + project + task the
 * first time it meets one (remembered in odoo_record_map as entity
 * `timesheet_job_orders`, so later lines and later runs reuse it), and
 * files each line under it. Odoo timesheets carry no number, so both
 * draw theirs from this system's own counters.
 *
 * HISTORY ONLY: each line arrives already approved, with Odoo's hours
 * exactly as logged (no SRV-007 re-rounding of work already billed),
 * and outcome not_hour_metered -- it does NOT deduct from any
 * contract, because a migrated contract's balance already arrives as
 * its consumed_hours, and deducting again would count those hours
 * twice.
 *
 * The employee must already be a staff user here (by full name, email
 * or username) -- a former employee needs an inactive user first.
 */
class TimesheetsImporter extends EntityImporter
{
    public function entity(): string
    {
        return 'timesheets';
    }

    public function targetType(Model $model): string
    {
        return 'service_record';
    }

    public function import(OdooRecord $record, ImportContext $ctx): Model
    {
        $row = $record->row;
        $employeeName = $row->get('employee_id', 'employee', 'user_id', 'user');
        if ($employeeName === null) {
            throw new RowFailed('No employee on this timesheet line.');
        }
        $employee = $ctx->user($employeeName);
        if ($employee === null) {
            throw new RowFailed("Employee \"{$employeeName}\" is not a staff user here -- create them (inactive, if they have left) first.");
        }

        $hours = $row->get('unit_amount', 'quantity', 'time spent', 'hours spent', 'duration');
        if ($hours === null || ! is_numeric($hours) || (float) $hours <= 0) {
            throw new RowFailed('Hours spent (unit_amount) must be a number greater than zero.');
        }
        $minutes = (int) round((float) $hours * 60);

        $workDate = $ctx->date($row->get('date'), 'date');
        $customer = $ctx->customer($row, 'partner_id', 'customer', 'partner');
        $jobOrder = $this->jobOrder($row->get('project_id', 'project'), $row->get('task_id', 'task'), $customer->id, $workDate, $ctx);

        return ServiceRecord::create([
            'company_id' => $ctx->company->id,
            'job_order_id' => $jobOrder->id,
            'employee_user_id' => $employee->id,
            'service_record_number' => Numbering::next($ctx->company->id, 'service_record', $workDate),
            'work_date' => $workDate->toDateString(),
            'raw_minutes' => $minutes,
            'rounded_minutes' => $minutes,
            'status' => ServiceRecord::STATUS_APPROVED,
            'outcome' => ServiceRecord::OUTCOME_NOT_HOUR_METERED,
            'completion_status' => ServiceRecord::COMPLETED,
            'work_description' => $row->get('name', 'description'),
            'submitted_at' => $workDate,
            'approved_at' => $workDate,
        ]);
    }

    private function jobOrder(?string $project, ?string $task, string $customerId, Carbon $workDate, ImportContext $ctx): JobOrder
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
            'subject' => mb_substr($subject !== '' ? "Odoo: {$subject}" : 'Odoo timesheets', 0, 255),
            'job_order_type' => JobOrder::TYPE_SUPPORT,
            'status' => JobOrder::STATUS_CLOSED,
            'closed_at' => Carbon::now(),
        ]);
        $ctx->remember('timesheet_job_orders', $key, 'job_order', $jobOrder);
        $ctx->warn("Opened closed Job Order {$jobOrder->job_order_number} \"{$jobOrder->subject}\" to hold this customer's migrated timesheets.");

        return $jobOrder;
    }

    public function describe(Model $model): string
    {
        return "Service Record {$model->service_record_number}, {$model->rounded_minutes} min";
    }
}
