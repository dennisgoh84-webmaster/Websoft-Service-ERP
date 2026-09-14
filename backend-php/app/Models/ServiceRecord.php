<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Service Records (formerly "Timesheets"), implementing SRV-007
 * (15-min rounding) and SRV-015 (3-business-day submission deadline).
 * Mirrors backend/app/models/service_records.py's ServiceRecord --
 * see that file's docstring for open item 9.1 (who approves, still
 * deferred; SERVICE_RECORD_APPROVER_ROLES in
 * App\Services\ServiceRecordService is a pragmatic default, not a
 * confirmed rule).
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

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const OUTCOME_PENDING = 'pending';

    public const OUTCOME_CONTRACT_DEDUCTION = 'contract_deduction';

    public const OUTCOME_EXCESS_USAGE = 'excess_usage';

    public const OUTCOME_NOT_HOUR_METERED = 'not_hour_metered';

    public const COMPLETED = 'C';

    public const UNCOMPLETED = 'U';

    protected $fillable = [
        'company_id', 'job_order_id', 'employee_user_id', 'service_record_number',
        'work_date', 'raw_minutes', 'rounded_minutes', 'status', 'outcome',
        'completion_status', 'is_after_hours', 'deducted_minutes', 'work_description',
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
}
