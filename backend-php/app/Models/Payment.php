<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money received from a customer (the Receipt Voucher). Mirrors
 * backend/app/models/payments.py's Payment -- AR-001: recorded when
 * it arrives; what it settles is decided separately (see
 * PaymentAllocation).
 */
class Payment extends Model
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
        'company_id', 'customer_id', 'voucher_number', 'payment_date', 'amount_sgd',
        'method', 'reference', 'notes', 'bank_account_id', 'recorded_by_user_id',
        // Data Migration (docs/data-migration.md) -- zero/null on every
        // receipt recorded in this system.
        'pre_migration_allocated_sgd', 'migrated_at',
    ];

    protected $attributes = ['method' => self::METHOD_BANK_TRANSFER, 'pre_migration_allocated_sgd' => '0.00'];

    protected $casts = [
        'payment_date' => 'date',
        'amount_sgd' => 'decimal:2',
        'pre_migration_allocated_sgd' => 'decimal:2',
        'migrated_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CompanyIndividual::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * Allocations made here, plus -- on a receipt migrated from the old
     * system -- what it had already been applied to before cut-over,
     * which has no PaymentAllocation rows behind it.
     */
    public function allocatedSgd(): Money
    {
        return $this->allocations->reduce(
            fn (Money $carry, PaymentAllocation $a) => $carry->plus(Money::of($a->amount_sgd)),
            Money::of($this->pre_migration_allocated_sgd ?? 0),
        );
    }

    public function unallocatedSgd(): Money
    {
        return Money::of($this->amount_sgd)->minus($this->allocatedSgd());
    }
}
