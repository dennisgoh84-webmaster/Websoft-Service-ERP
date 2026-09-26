<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a credit note's money went (2026-09-26): its own invoice,
 * another invoice of the customer (credit on account set against it),
 * or a refund Payment Voucher. Never deleted.
 */
class CreditNoteApplication extends Model
{
    use HasUuidPrimaryKey;

    public const KIND_INVOICE = 'invoice';

    public const KIND_REFUND = 'refund';

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'credit_note_id', 'kind', 'invoice_id', 'supplier_payment_id',
        'amount_fx', 'amount_sgd', 'note_amount_sgd', 'applied_by_user_id', 'applied_at',
    ];

    protected $casts = ['applied_at' => 'datetime'];

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
