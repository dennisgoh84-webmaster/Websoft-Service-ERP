<?php

namespace App\Services\DataMigration\Importers;

use App\Models\Contract;
use App\Models\JobOrder;
use App\Services\DataMigration\EntityImporter;
use App\Services\DataMigration\ImportContext;
use App\Services\DataMigration\RowFailed;
use App\Services\DataMigration\SourceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ZSOFT Job Orders -> Job Order, keeping the old number and status.
 *
 * History as it stood: no implementation tasks are generated, no
 * notifications go out, and an old job order linked to a contract does
 * not re-check the contract's hour-sharing list. The contract is found
 * by its (migrated) contract number; staff the old system knew but who
 * are not users here become inactive users.
 */
class JobOrdersImporter extends EntityImporter
{
    private const STATUSES = [
        'open' => JobOrder::STATUS_OPEN, 'new' => JobOrder::STATUS_OPEN, 'pending' => JobOrder::STATUS_OPEN,
        'assigned' => JobOrder::STATUS_ASSIGNED, 'in progress' => JobOrder::STATUS_ASSIGNED, 'wip' => JobOrder::STATUS_ASSIGNED,
        'closed' => JobOrder::STATUS_CLOSED, 'done' => JobOrder::STATUS_CLOSED, 'completed' => JobOrder::STATUS_CLOSED, 'complete' => JobOrder::STATUS_CLOSED,
        'void' => JobOrder::STATUS_VOID, 'cancelled' => JobOrder::STATUS_VOID, 'canceled' => JobOrder::STATUS_VOID,
    ];

    private const PRIORITIES = [
        'low' => JobOrder::PRIORITY_LOW, 'normal' => JobOrder::PRIORITY_NORMAL, 'medium' => JobOrder::PRIORITY_NORMAL,
        'high' => JobOrder::PRIORITY_HIGH, 'urgent' => JobOrder::PRIORITY_CRITICAL, 'critical' => JobOrder::PRIORITY_CRITICAL,
    ];

    public function entity(): string
    {
        return 'job_orders';
    }

    public function label(): string
    {
        return 'Job Orders';
    }

    public function fields(): array
    {
        return self::sourceIdField('If left unmapped, the job order number is used.') + [
            'name' => ['label' => 'Job order number', 'required' => true, 'aliases' => ['job order no', 'job order number', 'jo no', 'jo_no', 'job no', 'number']],
        ] + self::partyFields() + [
            'subject' => ['label' => 'Subject', 'required' => true, 'aliases' => ['subject', 'title', 'description', 'problem']],
            'status' => ['label' => 'Status', 'required' => true, 'aliases' => ['status', 'state'], 'hint' => 'Open / Assigned / Closed / Void.'],
            'job_order_type' => ['label' => 'Type', 'aliases' => ['type', 'job type', 'job order type'], 'hint' => 'Support or Project. Blank = Support.'],
            'priority' => ['label' => 'Priority', 'aliases' => ['priority'], 'hint' => 'Low / Normal / High / Critical. Blank = Normal.'],
            'contract' => ['label' => 'Contract number', 'aliases' => ['contract', 'contract no', 'contract number', 'contract_no']],
            'assigned_to' => ['label' => 'Assigned to', 'aliases' => ['assigned to', 'engineer', 'technician', 'assignee', 'staff']],
            'opened_date' => ['label' => 'Opened date', 'aliases' => ['date', 'opened date', 'open date', 'created date', 'job date']],
            'due_date' => ['label' => 'Due date', 'aliases' => ['due date', 'deadline', 'date_deadline']],
            'closed_date' => ['label' => 'Closed date', 'aliases' => ['closed date', 'close date', 'completed date', 'date_closed']],
        ];
    }

    public function targetType(Model $model): string
    {
        return 'job_order';
    }

    public function sourceRef(SourceRecord $record, ImportContext $ctx): ?string
    {
        return $record->row->get('id', 'name');
    }

    public function import(SourceRecord $record, ImportContext $ctx): Model
    {
        $row = $record->row;
        $number = $row->get('name');
        if ($number === null) {
            throw new RowFailed('Job order number is required.');
        }
        // Before the cut-off date, only a job order not yet closed or void comes across.
        $mapped = self::STATUSES[mb_strtolower((string) $row->get('status'))] ?? null;
        $ctx->skipBeforeCutoff(
            $ctx->date($row->get('opened_date'), 'Opened date', required: false) ?? $ctx->date($row->get('closed_date'), 'Closed date', required: false),
            ! in_array($mapped, [JobOrder::STATUS_CLOSED, JobOrder::STATUS_VOID], true), "Job Order {$number}");
        $ctx->requireUnusedNumber(JobOrder::class, 'job_order_number', $number);
        $customer = $ctx->customer($row);

        $subject = $row->get('subject');
        if ($subject === null) {
            throw new RowFailed('Subject is required.');
        }
        $oldStatus = $row->get('status');
        $status = $oldStatus === null ? null : (self::STATUSES[mb_strtolower($oldStatus)] ?? null);
        if ($status === null) {
            throw new RowFailed('Status "'.($oldStatus ?? '').'" has no equivalent job order status here.');
        }
        $type = mb_strtolower($row->get('job_order_type') ?? JobOrder::TYPE_SUPPORT);
        if (! in_array($type, [JobOrder::TYPE_SUPPORT, JobOrder::TYPE_PROJECT], true)) {
            throw new RowFailed("Type \"{$type}\" must be Support or Project.");
        }
        $priority = self::PRIORITIES[mb_strtolower($row->get('priority') ?? 'normal')] ?? null;
        if ($priority === null) {
            throw new RowFailed('Priority "'.$row->get('priority').'" must be Low, Normal, High or Critical.');
        }

        $contractId = null;
        if (($contractNumber = $row->get('contract')) !== null) {
            $contractId = Contract::where('company_id', $ctx->company->id)->where('contract_number', $contractNumber)->value('id');
            if ($contractId === null) {
                throw new RowFailed("Contract {$contractNumber} is not here -- import Contracts first.");
            }
        }

        $assignee = ($name = $row->get('assigned_to')) !== null ? $ctx->staff($name) : null;
        $opened = $ctx->date($row->get('opened_date'), 'Opened date', required: false);
        $closed = $ctx->date($row->get('closed_date'), 'Closed date', required: false);
        if ($status === JobOrder::STATUS_ASSIGNED && $assignee === null) {
            $status = JobOrder::STATUS_OPEN;
            $ctx->warn('Assigned in the old system but no one is named; imported as Open.');
        }

        $jobOrder = new JobOrder([
            'company_id' => $ctx->company->id,
            'customer_id' => $customer->id,
            'contract_id' => $contractId,
            'job_order_number' => $number,
            'subject' => mb_substr($subject, 0, 255),
            'job_order_type' => $type,
            'priority' => $priority,
            'status' => $status,
            'assigned_to_user_id' => $assignee?->id,
            'due_date' => $ctx->date($row->get('due_date'), 'Due date', required: false)?->toDateString(),
            'closed_at' => in_array($status, [JobOrder::STATUS_CLOSED, JobOrder::STATUS_VOID], true) ? ($closed ?? $opened ?? Carbon::now()) : null,
            'void_reason' => $status === JobOrder::STATUS_VOID ? "Void in {$ctx->sourceLabel()} before migration." : null,
        ]);
        if ($opened !== null) {
            $jobOrder->created_at = $opened;
        }
        $jobOrder->save();

        return $jobOrder;
    }

    public function describe(Model $model): string
    {
        return "Job Order {$model->job_order_number} ({$model->status})";
    }
}
