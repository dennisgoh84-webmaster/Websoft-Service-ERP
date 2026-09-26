<?php

namespace App\Models;

use App\Models\Concerns\HasLineNumber;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One returned item on a Goods Return Note. Mirrors
 * backend/app/models/inventory.py's GoodsReturnNoteLine.
 */
class GoodsReturnNoteLine extends Model
{
    use HasFactory, HasLineNumber, HasUuidPrimaryKey;

    public const LINE_PARENT_KEY = 'grtn_id';

    public $timestamps = false;

    protected $fillable = ['grtn_id', 'stock_item_id', 'quantity', 'unit_cost', 'total_cost', 'notes'];

    protected $casts = [
        'quantity' => 'integer',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:2',
    ];

    public function grtn(): BelongsTo
    {
        return $this->belongsTo(GoodsReturnNote::class, 'grtn_id');
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }
}
