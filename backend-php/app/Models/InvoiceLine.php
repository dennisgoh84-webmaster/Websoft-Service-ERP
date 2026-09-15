<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a manually raised Sales Invoice. See the
 * `create_invoice_lines_table` migration for why lines are optional
 * and what a stock line does on issue.
 *
 * `unit_cost_sgd` is the weighted average at the moment of issue, held
 * here so an invoice's gross profit never moves when a later goods
 * receipt re-weights the item.
 */
class InvoiceLine extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'company_id', 'invoice_id', 'line_no', 'description', 'product_id',
        'stock_item_id', 'warehouse_id', 'quantity', 'unit_of_measure',
        'unit_price_sgd', 'line_amount_sgd', 'unit_cost_sgd', 'cost_amount_sgd',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'line_no' => 'integer',
        'unit_price_sgd' => 'decimal:2',
        'line_amount_sgd' => 'decimal:2',
        'unit_cost_sgd' => 'decimal:4',
        'cost_amount_sgd' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
