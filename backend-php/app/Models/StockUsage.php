<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Setup master: intended usage (e.g. Resale, Internal, Project).
 * Mirrors backend/app/models/inventory.py's StockUsage.
 *
 * Never deleted -- deactivated through the `/toggle` route.
 */
class StockUsage extends Model
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
