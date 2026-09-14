<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Setup master: brand (e.g. Cisco, HP, Dell). StockModels are its
 * children. Mirrors backend/app/models/inventory.py's StockBrand.
 *
 * Never deleted -- deactivated through the `/toggle` route. Note the
 * Python relationship declares `cascade="all, delete-orphan"` on
 * `models`, but nothing in the router ever deletes a brand, so no
 * cascade is replicated here (a cascade that can never fire is not a
 * business rule).
 */
class StockBrand extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    protected $fillable = ['company_id', 'name', 'is_active'];

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

    public function models(): HasMany
    {
        return $this->hasMany(StockModel::class, 'brand_id');
    }
}
