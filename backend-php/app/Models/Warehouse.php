<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A physical location where stock is held. Mirrors
 * backend/app/models/inventory.py's Warehouse.
 *
 * Never deleted -- deactivated by setting is_active false (the
 * Warehouses screen's Activate/Deactivate button), per CLAUDE.md's
 * "never permanently delete important business records".
 */
class Warehouse extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    protected $fillable = ['company_id', 'code', 'name', 'address', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
