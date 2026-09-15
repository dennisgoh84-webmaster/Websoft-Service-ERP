<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\StockDocumentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Goods Issue Note: stock leaving the warehouse to a job order, a
 * customer, or internal use. Requested by Dennis 2026-09-15.
 *
 * NEW in backend-php -- `backend/` (Python) has no equivalent model or
 * router. It is what finally writes the `issue` stock movement type,
 * which existed but was never produced by anything.
 *
 * Confirming deducts at the item's weighted average cost, which the
 * issue itself never moves, and refuses outright if the warehouse does
 * not hold enough -- never a partial issue, and never a negative
 * on-hand quantity.
 */
class GoodsIssueNote extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public const STATUS_DRAFT = StockDocumentStatus::DRAFT;

    public const STATUS_CONFIRMED = StockDocumentStatus::CONFIRMED;

    protected $fillable = [
        'company_id', 'gin_number', 'warehouse_id', 'customer_id', 'job_order_id',
        'issue_date', 'reason', 'status', 'notes', 'created_by',
    ];

    protected $attributes = ['status' => StockDocumentStatus::DRAFT];

    protected $casts = [
        'issue_date' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsIssueNoteLine::class, 'gin_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
