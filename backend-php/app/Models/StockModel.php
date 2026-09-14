<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Setup master: a model under a brand (e.g. Cisco Catalyst 9300).
 * Mirrors backend/app/models/inventory.py's StockModel.
 *
 * Deliberately has NO company_id of its own, exactly like the Python
 * model -- a model's company is its brand's company, and every route
 * scopes a model query by joining through `stock_brands` (see
 * App\Http\Controllers\Api\StockSetupController::modelOrFail).
 */
class StockModel extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    protected $fillable = ['brand_id', 'name', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(StockBrand::class, 'brand_id');
    }
}
