<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Which record a source-system row became (docs/data-migration.md).
 * `action` is `created` (this batch made the record) or `linked` (the
 * row matched a record already here). Kept after a roll back, stamped
 * rolled_back_at -- the trail from every migrated record back to its
 * origin is permanent.
 */
class MigrationRecordMap extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'migration_record_map';

    public $timestamps = false;

    public const ACTION_CREATED = 'created';

    public const ACTION_LINKED = 'linked';

    protected $fillable = ['company_id', 'source', 'entity', 'source_ref', 'target_type', 'target_id', 'action', 'batch_id', 'rolled_back_at'];

    protected $casts = ['created_at' => 'datetime', 'rolled_back_at' => 'datetime'];

    /** @param  Builder<MigrationRecordMap>  $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('rolled_back_at');
    }
}
