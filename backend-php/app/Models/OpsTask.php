<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One freeform task on a staff member's Ops Dashboard. Mirrors
 * backend/app/models/ops_tasks.py's OpsTask.
 *
 * Archiving sets `is_active` false; a task is never deleted.
 */
class OpsTask extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public const UPDATED_AT = 'updated_at';

    public const CREATED_AT = 'created_at';

    public const STATUS_NOT_STARTED = 'not_started';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_WATCH = 'watch';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_DONE = 'done';

    public const STATUSES = [
        self::STATUS_NOT_STARTED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_WATCH,
        self::STATUS_BLOCKED,
        self::STATUS_DONE,
    ];

    protected $fillable = [
        'company_id', 'category_id', 'owner_user_id', 'title', 'status',
        'next_action', 'owner_label', 'due_label', 'follow_up_staff_id',
        'follow_up_date', 'is_sample', 'is_active',
    ];

    protected $attributes = ['status' => self::STATUS_NOT_STARTED, 'is_sample' => false, 'is_active' => true];

    protected $casts = [
        'follow_up_date' => 'date',
        'is_sample' => 'boolean',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(OpsTaskCategory::class, 'category_id');
    }
}
