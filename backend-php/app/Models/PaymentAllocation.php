<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of one payment settles one invoice. Manual, per AR-001.
 * Mirrors backend/app/models/payments.py's PaymentAllocation.
 */
class PaymentAllocation extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    // amount_sgd: the receipt's side, at its rate; invoice_amount_sgd: what it
    // cleared off the invoice, at the invoice's rate; the difference is the
    // realised exchange gain (+) or loss (-) booked with it (multi-currency).
    protected $fillable = ['company_id', 'payment_id', 'invoice_id', 'amount_sgd', 'amount_fx', 'invoice_amount_sgd', 'fx_difference_sgd'];

    protected $casts = [
        'amount_sgd' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
