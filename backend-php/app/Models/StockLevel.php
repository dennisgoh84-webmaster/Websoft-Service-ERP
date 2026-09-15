<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Current quantity of a stock item at a warehouse.
 *
 * DIVERGES FROM backend/app/models/inventory.py's StockLevel, which
 * also holds a per-warehouse `avg_cost`. Confirmed with Dennis
 * 2026-09-15: the weighted average cost is now held ONCE per item
 * across all locations and branches, on `stock_items`, so this table
 * carries quantity only. See App\Services\InventoryService.
 *
 * Only ever written by App\Services\InventoryService -- nothing else
 * may set a quantity directly, so the stock ledger
 * (`stock_movements`) always explains the balance.
 *
 * Has an `updated_at` but no `created_at`, exactly like the Python
 * model -- hence the CREATED_AT = null below.
 */
class StockLevel extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public const CREATED_AT = null;

    protected $fillable = ['company_id', 'stock_item_id', 'warehouse_id', 'quantity'];

    protected $attributes = [
        'quantity' => 0,
    ];

    protected $casts = [
        'quantity' => 'integer',
        'updated_at' => 'datetime',
    ];

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
