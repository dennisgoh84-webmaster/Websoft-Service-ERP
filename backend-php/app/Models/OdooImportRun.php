<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * One run of `php artisan odoo:import` -- dry runs included -- with
 * its row-by-row report. See docs/odoo-migration.md.
 */
class OdooImportRun extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    public const MODE_DRY_RUN = 'dry_run';

    public const MODE_COMMIT = 'commit';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    /** Commit refused: at least one row failed, so nothing was written. */
    public const STATUS_FAILED = 'failed';

    /** Validated end to end, then rolled back on purpose. */
    public const STATUS_DRY_RUN = 'dry_run';

    protected $fillable = [
        'company_id', 'entity', 'source_filename', 'mode', 'status',
        'rows_read', 'rows_created', 'rows_already_imported', 'rows_skipped', 'rows_failed',
        'report', 'started_by_user_id', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'report' => 'array',
        'rows_read' => 'integer',
        'rows_created' => 'integer',
        'rows_already_imported' => 'integer',
        'rows_skipped' => 'integer',
        'rows_failed' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
