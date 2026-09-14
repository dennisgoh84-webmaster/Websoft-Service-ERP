<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable record of a stock quantity change. Mirrors
 * backend/app/models/inventory.py's StockMovement, including its
 * MovementType enum (the constants below carry the same lowercase
 * string values the Python enum stores and the frontend reads).
 *
 * Every GRN/GTN/GRTN/Adjustment line writes one of these (a transfer
 * writes two: a deduction at the source and a receipt at the
 * destination). Never updated and never deleted -- this is the stock
 * module's history, per CLAUDE.md's "never permanently delete
 * important business records".
 */
class StockMovement extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    /** GRN -- goods received from a supplier. */
    public const TYPE_RECEIVE = 'receive';

    /** GTN -- leaving the source warehouse. */
    public const TYPE_TRANSFER_OUT = 'transfer_out';

    /**
     * Declared by the Python enum but never written by it: confirm_gtn
     * records the destination side of a transfer as a `receive`, not a
     * `transfer_in`, because it goes through the same weighted-average
     * receive_stock() path. Kept here so the value set matches the
     * Python enum exactly rather than quietly shrinking it.
     */
    public const TYPE_TRANSFER_IN = 'transfer_in';

    /** GRTN -- returned to the supplier. */
    public const TYPE_RETURN_OUT = 'return_out';

    /** Stock Adjustment (INV-001), in either direction. */
    public const TYPE_ADJUSTMENT = 'adjustment';

    /** Declared for future use by the Python enum: issue to a job order / sale. */
    public const TYPE_ISSUE = 'issue';

    protected $fillable = [
        'company_id', 'stock_item_id', 'warehouse_id', 'movement_type', 'quantity',
        'unit_cost', 'total_cost', 'reference_type', 'reference_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'quantity' => 'integer',
        // 4dp unit cost / 2dp extended cost -- matches the Python
        // Numeric(14, 4) / Numeric(14, 2) columns exactly.
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:2',
        'created_at' => 'datetime',
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
