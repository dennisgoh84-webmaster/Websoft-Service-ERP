<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One uploaded file in Data Migration (docs/data-migration.md), through
 * its whole life: uploaded -> dry run -> imported -> rolled back. It
 * keeps the file, the field mapping and duplicate decisions used, the
 * row-by-row report of its latest run, live progress, and the
 * roll-back record. Never deleted.
 */
class MigrationBatch extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    public const SOURCE_ODOO = 'odoo';

    public const SOURCE_ZSOFT = 'zsoft';

    public const MODE_DRY_RUN = 'dry_run';

    public const MODE_COMMIT = 'commit';

    public const STATUS_UPLOADED = 'uploaded';

    /** Waiting for / inside its background run (dry run or import, per `mode`). */
    public const STATUS_RUNNING = 'running';

    /** Validated end to end, then rolled back on purpose. */
    public const STATUS_DRY_RUN = 'dry_run';

    /** Import refused (a failed or undecided row) or crashed -- nothing written. */
    public const STATUS_FAILED = 'failed';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    protected $fillable = [
        'company_id', 'source', 'entity', 'batch_number', 'source_filename', 'stored_path', 'sheet_name',
        'headers', 'sample_row', 'mapping', 'decisions', 'mode', 'status',
        'rows_read', 'rows_created', 'rows_linked', 'rows_already_imported', 'rows_skipped',
        'rows_failed', 'rows_needs_decision', 'progress_done', 'progress_total',
        'report', 'error_message', 'started_by_user_id', 'started_at', 'finished_at', 'imported_at',
        'rolled_back_at', 'rolled_back_by_user_id', 'rollback_reason', 'rollback_report',
    ];

    protected $casts = [
        'headers' => 'array',
        'sample_row' => 'array',
        'mapping' => 'array',
        'decisions' => 'array',
        'report' => 'array',
        'rollback_report' => 'array',
        'rows_read' => 'integer',
        'rows_created' => 'integer',
        'rows_linked' => 'integer',
        'rows_already_imported' => 'integer',
        'rows_skipped' => 'integer',
        'rows_failed' => 'integer',
        'rows_needs_decision' => 'integer',
        'progress_done' => 'integer',
        'progress_total' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'imported_at' => 'datetime',
        'rolled_back_at' => 'datetime',
    ];

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    public function rolledBackBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rolled_back_by_user_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
