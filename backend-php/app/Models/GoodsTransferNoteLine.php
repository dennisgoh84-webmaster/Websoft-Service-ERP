<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One transferred item on a Goods Transfer Note. Mirrors
 * backend/app/models/inventory.py's GoodsTransferNoteLine -- it
 * deliberately carries NO cost: the units move at the source
 * warehouse's weighted average cost, read at confirm time.
 */
class GoodsTransferNoteLine extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['gtn_id', 'stock_item_id', 'quantity', 'notes'];

    protected $casts = ['quantity' => 'integer'];

    public function gtn(): BelongsTo
    {
        return $this->belongsTo(GoodsTransferNote::class, 'gtn_id');
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }
}
