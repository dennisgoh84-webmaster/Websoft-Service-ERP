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

    // amount_sgd: the payment's side, at its rate; bill_amount_sgd: what it
    // cleared off the bill, at the bill's rate; fx_difference_sgd: the
    // realised exchange gain (+) or loss (-) booked with it (multi-currency).
    protected $fillable = ['company_id', 'payment_id', 'supplier_invoice_id', 'amount_sgd', 'amount_fx', 'bill_amount_sgd', 'fx_difference_sgd'];

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
