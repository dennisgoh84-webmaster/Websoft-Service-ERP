<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * INV-001 (CONFIRMED 2026-09-09, see docs/business-requirements.md):
 * stock adjustments for discrepancies, damage, loss or a count
 * variance **require manager approval before taking effect** --
 * warehouse/hardware staff cannot adjust stock unilaterally.
 *
 * That rule is carried by this status machine:
 *   draft -> pending_approval -> approved (stock changes take effect)
 *                             -> rejected (no stock change, ever)
 *
 * Mirrors backend/app/models/inventory.py's StockAdjustment and its
 * AdjustmentStatus enum (the constants below carry the same lowercase
 * string values the frontend reads).
 *
 * Who counts as "a manager" is Group Authority's job, not this
 * model's: the approve route is gated at FULL on the
 * `stock_adjustment` module key, exactly like the Python router. No
 * additional named-role check is invented here, and neither backend
 * stops a creator approving their own adjustment -- that separation
 * of duties was never confirmed, so it is not assumed.
 */
class StockAdjustment extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'company_id', 'adj_number', 'warehouse_id', 'adjustment_date', 'reason',
        'status', 'approved_by', 'approved_at', 'created_by',
    ];

    protected $attributes = ['status' => self::STATUS_DRAFT];

    protected $casts = [
        'adjustment_date' => 'datetime',
        'approved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class, 'adjustment_id')->orderBy('line_no')->orderBy('id');
    }
}
