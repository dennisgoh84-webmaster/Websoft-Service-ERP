<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Service Records (formerly "Timesheets"), implementing SRV-007
 * (15-min rounding), SRV-015 (3-business-day submission deadline),
 * SRV-019 (approval by Nico or Cherish only, within a week of
 * submission -- Dennis, 2026-09-15, settling open item 9.1; see
 * App\Services\ServiceRecordService::APPROVER_ROLES) and SRV-020
 * (the Job Order's billing classification decides the outcome).
 * Converted from backend/app/models/service_records.py's ServiceRecord.
 */
class ServiceRecord extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    // SRV-007.
    public const HOUR_ROUNDING_MINUTES = 15;

    // SRV-015: Service Record submission deadline (business days,
    // treated as calendar days for this build).
    public const SUBMISSION_DEADLINE_DAYS = 3;

    // SRV-019: a submitted record is to be approved within a week. Past
    // that it is flagged as overdue for approval -- never auto-approved
    // (nobody asked for that), just surfaced on the approval queue and
    // the Company Dashboard.
    public const APPROVAL_DEADLINE_DAYS = 7;

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const OUTCOME_PENDING = 'pending';

    public const OUTCOME_CONTRACT_DEDUCTION = 'contract_deduction';

    public const OUTCOME_EXCESS_USAGE = 'excess_usage';

    public const OUTCOME_NOT_HOUR_METERED = 'not_hour_metered';

    // SRV-020: the Job Order was classified BILLABLE -- nothing deducted
    // from contract hours, the time is charged through the Job Order's
    // own billing.
    public const OUTCOME_BILLABLE = 'billable';

    // SRV-020: the Job Order was classified NON_BILLABLE -- nothing
    // deducted, nothing billed; recorded for the audit trail only.
    public const OUTCOME_NON_BILLABLE = 'non_billable';

    public const COMPLETED = 'C';

    public const UNCOMPLETED = 'U';

    protected $fillable = [
        'company_id', 'job_order_id', 'employee_user_id', 'service_record_number',
        'work_date', 'raw_minutes', 'rounded_minutes', 'status', 'outcome',
        'completion_status', 'is_after_hours', 'deducted_minutes', 'work_description', 'submitted_at',
        'time_in', 'time_out', 'approved_at', 'approved_by_user_id',
    ];

    protected $casts = [
        'work_date' => 'date',
        'raw_minutes' => 'integer',
        'rounded_minutes' => 'integer',
        'is_after_hours' => 'boolean',
        'deducted_minutes' => 'integer',
        'time_in' => 'datetime',
        'time_out' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    /** SRV-007: round a logged-time entry up to the nearest 15 minutes. */
    public static function roundUpToNearest(int $minutes, int $increment = self::HOUR_ROUNDING_MINUTES): int
    {
        if ($minutes <= 0) {
            return 0;
        }

        return (int) (ceil($minutes / $increment) * $increment);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
    }

    /**
     * SRV-015: flagged missing if not submitted within 3 business days
     * of the work being performed. (Business-day precision is a future
     * refinement; this build uses calendar days, same as Python.)
     */
    public function isLate(): bool
    {
        $submittedDate = Carbon::parse($this->submitted_at)->startOfDay();
        $workDate = Carbon::parse($this->work_date)->startOfDay();

        return $workDate->diffInDays($submittedDate, false) > self::SUBMISSION_DEADLINE_DAYS;
    }

    /** SRV-019: the date by which a submitted record should have been approved. */
    public function approvalDueAt(): ?Carbon
    {
        if ($this->submitted_at === null) {
            return null;
        }

        return Carbon::parse($this->submitted_at)->addDays(self::APPROVAL_DEADLINE_DAYS);
    }

    /** SRV-019: still awaiting approval more than a week after submission. */
    public function isApprovalOverdue(): bool
    {
        $due = $this->approvalDueAt();

        return $this->status === self::STATUS_SUBMITTED && $due !== null && Carbon::now()->greaterThan($due);
    }
}
