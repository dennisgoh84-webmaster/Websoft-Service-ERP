<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\StockDocumentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Return of goods to a supplier. Mirrors
 * backend/app/models/inventory.py's GoodsReturnNote.
 *
 * Confirming one deducts stock at the warehouse's current weighted
 * average cost -- NOT at the line's own `unit_cost`, which records
 * what is being claimed back from the supplier and can legitimately
 * differ. Same behaviour as the Python service.
 */
class GoodsReturnNote extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public const STATUS_DRAFT = StockDocumentStatus::DRAFT;

    public const STATUS_CONFIRMED = StockDocumentStatus::CONFIRMED;

    protected $fillable = [
        'company_id', 'grtn_number', 'warehouse_id', 'supplier_id',
        'return_date', 'reason', 'status', 'notes', 'created_by',
    ];

    protected $attributes = ['status' => StockDocumentStatus::DRAFT];

    protected $casts = [
        'return_date' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(CompanyIndividual::class, 'supplier_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReturnNoteLine::class, 'grtn_id')->orderBy('id');
    }
}
