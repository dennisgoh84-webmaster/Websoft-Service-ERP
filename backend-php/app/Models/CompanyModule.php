<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-company module enablement/license. One row per (company, module).
 * Mirrors backend/app/models/licensing.py's CompanyModule.
 */
class CompanyModule extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'company_modules';

    public $timestamps = false;

    protected $fillable = ['company_id', 'module_key', 'enabled', 'license_type', 'notes', 'enabled_at'];

    protected $casts = [
        'enabled' => 'boolean',
        'enabled_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Mirrors backend/app/models/licensing.py's LicenseType.
    public const INCLUDED = 'included';

    public const ADD_ON = 'add_on';

    public const TRIAL = 'trial';

    public function module(): BelongsTo
    {
        return $this->belongsTo(ModuleCatalog::class, 'module_key', 'key');
    }
}
