<?php

namespace App\Models;

use App\Models\Concerns\HasLineNumber;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One received item on a Goods Receive Note. Mirrors
 * backend/app/models/inventory.py's GoodsReceiveNoteLine.
 *
 * `unit_cost` is decimal:4 (Numeric(14, 4)) -- a stock cost, not 2dp
 * customer-facing money. `total_cost` is the 2dp extended value.
 */
class GoodsReceiveNoteLine extends Model
{
    use HasFactory, HasLineNumber, HasUuidPrimaryKey;

    public const LINE_PARENT_KEY = 'grn_id';

    public $timestamps = false;

    protected $fillable = ['grn_id', 'stock_item_id', 'quantity', 'unit_cost', 'total_cost', 'notes'];

    protected $casts = [
        'quantity' => 'integer',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:2',
    ];

    public function grn(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiveNote::class, 'grn_id');
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }
}
