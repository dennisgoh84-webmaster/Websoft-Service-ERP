<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/** One queued/finished upgrade or rollback -- see the migration that created `upgrade_requests`. */
class UpgradeRequest extends Model
{
    use HasUuidPrimaryKey;

    public const KIND_UPGRADE = 'upgrade';

    public const KIND_ROLLBACK = 'rollback';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_RUNNING];

    /** A request still 'running' this long after it started is treated as lost (host rebooted mid-upgrade). */
    public const STALE_RUNNING_MINUTES = 45;

    public $timestamps = false;

    protected $fillable = [
        'kind', 'target_ref', 'status', 'requested_by', 'requested_at',
        'started_at', 'finished_at', 'from_sha', 'to_sha', 'log', 'error',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
