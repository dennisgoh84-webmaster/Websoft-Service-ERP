<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * Which Websoft record an Odoo record became. Permanent -- it is the
 * trail from every migrated record back to its Odoo origin, and what
 * lets a re-run skip rows it has already imported. See
 * docs/odoo-migration.md.
 */
class OdooRecordMap extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'odoo_record_map';

    public $timestamps = false;

    protected $fillable = ['company_id', 'entity', 'odoo_ref', 'target_type', 'target_id', 'import_run_id'];

    protected $casts = ['created_at' => 'datetime'];
}
