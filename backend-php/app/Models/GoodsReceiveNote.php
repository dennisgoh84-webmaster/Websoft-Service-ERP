<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\StockDocumentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Receipt of goods from a supplier into a warehouse. Mirrors
 * backend/app/models/inventory.py's GoodsReceiveNote.
 *
 * Confirming a GRN is the only event that brings NEW cost information
 * into the system, so it is the only one that moves the INV-002
 * weighted average -- see App\Services\InventoryService::confirmGrn.
 *
 * Never deleted: a confirmed GRN is corrected by a further movement
 * (a Goods Return Note or a Stock Adjustment), not by removing it.
 */
class GoodsReceiveNote extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public const STATUS_DRAFT = StockDocumentStatus::DRAFT;

    public const STATUS_CONFIRMED = StockDocumentStatus::CONFIRMED;

    protected $fillable = [
        'company_id', 'grn_number', 'warehouse_id', 'supplier_id', 'purchase_order_id',
        'receive_date', 'status', 'notes', 'created_by',
    ];

    protected $attributes = ['status' => StockDocumentStatus::DRAFT];

    protected $casts = [
        'receive_date' => 'datetime',
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
        return $this->hasMany(GoodsReceiveNoteLine::class, 'grn_id')->orderBy('line_no')->orderBy('id');
    }
}
