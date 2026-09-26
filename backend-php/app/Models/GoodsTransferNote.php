<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\StockDocumentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Transfer of goods between two warehouses. Mirrors
 * backend/app/models/inventory.py's GoodsTransferNote.
 *
 * Confirming one deducts at the source and receives at the
 * destination, carrying the SOURCE warehouse's weighted average cost
 * across -- a transfer moves stock, it never revalues it. See
 * App\Services\InventoryService::confirmGtn.
 */
class GoodsTransferNote extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public const STATUS_DRAFT = StockDocumentStatus::DRAFT;

    public const STATUS_CONFIRMED = StockDocumentStatus::CONFIRMED;

    protected $fillable = [
        'company_id', 'gtn_number', 'from_warehouse_id', 'to_warehouse_id',
        'transfer_date', 'status', 'notes', 'created_by',
    ];

    protected $attributes = ['status' => StockDocumentStatus::DRAFT];

    protected $casts = [
        'transfer_date' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsTransferNoteLine::class, 'gtn_id')->orderBy('line_no')->orderBy('id');
    }
}
