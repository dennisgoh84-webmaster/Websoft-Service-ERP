<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One checklist task copied onto a Job Order from a selected Product's
 * Job Implementation Template -- see
 * App\Services\JobOrderImplementationTaskService. Mirrors
 * App\Models\ProjectMilestone's completion-tracking spirit (ordered,
 * completion gated to Sales Manager/Owner per 7.3) with a simpler
 * pending/completed lifecycle -- see the migration's docblock for why.
 */
class JobOrderImplementationTask extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'job_order_id', 'source_product_id', 'task_name', 'description', 'sort_order',
        'status', 'completed_by_user_id', 'completed_at',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function sourceProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'source_product_id');
    }
}
