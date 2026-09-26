<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money paid out to a supplier -- the Payment Voucher (PV). Mirrors
 * backend/app/models/payables.py's SupplierPayment.
 */
class SupplierPayment extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    public const METHOD_PAYNOW = 'paynow';

    public const METHOD_CHEQUE = 'cheque';

    public const METHOD_CASH = 'cash';

    public const METHOD_CREDIT_CARD = 'credit_card';

    public const METHOD_OTHER = 'other';

    protected $fillable = [
        'company_id', 'supplier_id', 'gl_account_id', 'voucher_number', 'payment_date', 'amount_sgd',
        'method', 'reference', 'notes', 'bank_account_id', 'paid_by_user_id',
    ];

    protected $attributes = ['method' => self::METHOD_BANK_TRANSFER];

    protected $casts = [
        'payment_date' => 'date',
        'amount_sgd' => 'decimal:2',
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

    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class, 'payment_id');
    }

    public function allocatedSgd(): Money
    {
        return $this->allocations->reduce(fn (Money $carry, SupplierPaymentAllocation $a) => $carry->plus(Money::of($a->amount_sgd)), Money::of(0));
    }

    /**
     * An "Other" voucher is against a GL account, not a Company /
     * Individual -- bank interest, bank charges and the like, which go
     * through a voucher rather than straight into the Bank Book (#49,
     * 31.1). Exactly one of supplier_id / gl_account_id is set.
     */
    public function isOther(): bool
    {
        return $this->gl_account_id !== null;
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'gl_account_id');
    }

    /** An Other voucher settles no bills, so it never has anything left to allocate. */
    public function unallocatedSgd(): Money
    {
        if ($this->isOther()) {
            return Money::of(0);
        }

        return Money::of($this->amount_sgd)->minus($this->allocatedSgd());
    }
}
