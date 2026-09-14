<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Setup master: stock item categories (e.g. Hardware, Software,
 * Consumable). Mirrors backend/app/models/inventory.py's StockCategory.
 *
 * Never deleted -- deactivated through the `/toggle` route, per
 * CLAUDE.md's "use soft-delete or archival where appropriate".
 */
class StockCategory extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    protected $fillable = ['company_id', 'code', 'name', 'is_active'];

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
