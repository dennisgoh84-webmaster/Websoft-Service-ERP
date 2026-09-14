<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scheduled milestone in a PROJECT-type Job Order. Mirrors
 * backend/app/models/job_orders.py's ProjectMilestone.
 */
class ProjectMilestone extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'job_order_id', 'milestone_type', 'label', 'sort_order', 'planned_start',
        'planned_end', 'actual_start', 'actual_end', 'assigned_user_id', 'status', 'notes',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'planned_start' => 'date',
        'planned_end' => 'date',
        'actual_start' => 'date',
        'actual_end' => 'date',
        'created_at' => 'datetime',
    ];

    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
    }
}
