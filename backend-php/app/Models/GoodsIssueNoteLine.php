<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a Goods Issue Note.
 *
 * `unit_cost` / `total_cost` are NOT supplied when the document is
 * raised -- stock leaves at the item's weighted average, so a cost
 * entered here would be a second, contradictory source of truth. They
 * are stamped on CONFIRM with the average that actually applied, so the
 * document still reads correctly after the average later moves.
 */
class GoodsIssueNoteLine extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    protected $table = 'goods_issue_note_lines';

    protected $fillable = ['gin_id', 'stock_item_id', 'quantity', 'unit_cost', 'total_cost', 'notes'];

    protected $casts = [
        'quantity' => 'integer',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:2',
    ];

    public function goodsIssueNote(): BelongsTo
    {
        return $this->belongsTo(GoodsIssueNote::class, 'gin_id');
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }
}
