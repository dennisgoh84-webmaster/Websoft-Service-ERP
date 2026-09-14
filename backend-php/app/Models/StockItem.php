<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An inventory item tracked by quantity. Mirrors
 * backend/app/models/inventory.py's StockItem exactly.
 *
 * Links to the Product/Service Catalog when the item is also sold
 * (`product_id`), but can exist independently for internal
 * consumables.
 *
 * BOUNDARY (deliberately preserved): Product's own "Is Stock" flag is
 * NOT wired to this table. docs/backlog.md and docs/planned-work.md #5
 * record that the full Product -> Stock Master link-up is deferred
 * pending the separate Websoft Stock Distribution ERP project, so the
 * Python code stops at this one optional FK -- and so does this
 * conversion. Inventing that link here would be inventing a business
 * rule nobody confirmed.
 */
class StockItem extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    protected $fillable = [
        'company_id', 'code', 'name', 'description', 'category', 'unit_of_measure',
        'product_id', 'reorder_level',
        // Extended fields (2026-09-13)
        'category_id', 'group_id', 'brand_id', 'model_id', 'usage_id',
        'barcode', 'part_number', 'invoice_description', 'memo', 'notes', 'dimensions',
        'is_active',
    ];

    protected $attributes = [
        'unit_of_measure' => 'PCS',
        'reorder_level' => 0,
        'is_active' => true,
    ];

    protected $casts = [
        'reorder_level' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockCategory(): BelongsTo
    {
        return $this->belongsTo(StockCategory::class, 'category_id');
    }

    public function stockGroup(): BelongsTo
    {
        return $this->belongsTo(StockGroup::class, 'group_id');
    }

    public function stockBrand(): BelongsTo
    {
        return $this->belongsTo(StockBrand::class, 'brand_id');
    }

    public function stockModel(): BelongsTo
    {
        return $this->belongsTo(StockModel::class, 'model_id');
    }

    public function stockUsage(): BelongsTo
    {
        return $this->belongsTo(StockUsage::class, 'usage_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(StockItemAttachment::class)->orderBy('created_at');
    }
}
