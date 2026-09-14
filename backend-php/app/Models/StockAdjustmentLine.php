<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One adjusted item on a Stock Adjustment. Mirrors
 * backend/app/models/inventory.py's StockAdjustmentLine.
 *
 * `quantity_change` is signed: +ve increases stock, -ve decreases it.
 * It carries no cost -- an adjustment has no new cost information, so
 * both directions move at the warehouse's current weighted average.
 */
class StockAdjustmentLine extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['adjustment_id', 'stock_item_id', 'quantity_change', 'notes'];

    protected $casts = ['quantity_change' => 'integer'];

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'adjustment_id');
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }
}
