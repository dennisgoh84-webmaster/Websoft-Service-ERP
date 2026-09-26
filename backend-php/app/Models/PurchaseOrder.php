<?php

namespace App\Models;

use App\Models\Concerns\HasCurrency;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What we committed to buy -- the document a supplier invoice is
 * matched against under PUR-002. Mirrors
 * backend/app/models/payables.py's PurchaseOrder.
 */
class PurchaseOrder extends Model
{
    use HasCurrency;
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_APPROVAL = 'pending_approval'; // PUR-001: above the threshold

    public const STATUS_APPROVED = 'approved';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'supplier_id', 'po_number', 'order_date', 'description',
        'amount_sgd', 'gst_amount_sgd', 'total_amount_sgd', 'status',
        'approved_by_user_id', 'approved_at', 'cancel_reason', 'cancelled_at', 'cancelled_by_user_id',
        'currency_code', 'exchange_rate', 'amount_fx', 'gst_amount_fx', 'total_amount_fx',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'gst_amount_sgd' => '0.00',
        'total_amount_sgd' => '0.00',
    ];

    protected $casts = [
        'cancelled_at' => 'datetime',
        'order_date' => 'date',
        'amount_sgd' => 'decimal:2',
        'gst_amount_sgd' => 'decimal:2',
        'total_amount_sgd' => 'decimal:2',
        'approved_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(CompanyIndividual::class, 'supplier_id');
    }

    /**
     * Bills raised against this PO -- "confirm and import to AP"
     * (2026-09-12) checks this to stop a PO being imported into AP
     * twice.
     */
    public function bills(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class, 'purchase_order_id');
    }
}
