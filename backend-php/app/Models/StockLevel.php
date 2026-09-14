<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Current quantity and INV-002 weighted average cost of a stock item at
 * a warehouse. Mirrors backend/app/models/inventory.py's StockLevel.
 *
 * Only ever written by App\Services\InventoryService -- nothing else
 * may set a quantity or an average cost directly, so the stock ledger
 * (`stock_movements`) always explains the balance.
 *
 * `avg_cost` is decimal:4, NOT the decimal:2 used for customer-facing
 * money -- the Python column is Numeric(14, 4). Arithmetic on it goes
 * through App\Support\Money with an explicit scale of 4; see
 * docs/php-conversion-plan.md's Decimal/money handling convention.
 *
 * Has an `updated_at` but no `created_at`, exactly like the Python
 * model -- hence the CREATED_AT = null below.
 */
class StockLevel extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public const CREATED_AT = null;

    protected $fillable = ['company_id', 'stock_item_id', 'warehouse_id', 'quantity', 'avg_cost'];

    protected $attributes = [
        'quantity' => 0,
        'avg_cost' => '0.0000',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'avg_cost' => 'decimal:4',
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
