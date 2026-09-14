<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixed catalog of business-area modules (docs/module-map.md). Mirrors
 * backend/app/models/licensing.py's Module -- named ModuleCatalog here
 * (rather than the PHP-legal but confusing `Module`) since the table
 * itself is still exactly `modules`.
 *
 * Primary key is the module key (a short string, e.g.
 * "service_contracts"), not a UUID -- same as the Python model.
 */
class ModuleCatalog extends Model
{
    protected $table = 'modules';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['key', 'name', 'description', 'is_built'];

    protected $casts = ['is_built' => 'boolean'];
}
