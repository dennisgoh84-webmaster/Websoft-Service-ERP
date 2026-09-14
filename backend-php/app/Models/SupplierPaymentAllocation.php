<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which bills a payment voucher settles. Manual, mirroring AR-001 --
 * deciding what a payment covers is Finance's call either way.
 * Mirrors backend/app/models/payables.py's SupplierPaymentAllocation.
 */
class SupplierPaymentAllocation extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['company_id', 'payment_id', 'supplier_invoice_id', 'amount_sgd'];

    protected $casts = [
        'amount_sgd' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class, 'payment_id');
    }

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }
}
