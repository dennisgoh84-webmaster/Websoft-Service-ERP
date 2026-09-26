<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the Bank Book -- a plain debit/credit entry against one
 * BankAccount, independent of the General Ledger's Journal Vouchers.
 * `debit_sgd` is money IN, `credit_sgd` is money OUT. Mirrors
 * backend/app/models/treasury.py's BankTransaction.
 *
 * Never hard-deleted -- a wrong entry is voided with a reason instead
 * (is_voided/void_reason), the same pattern as JobOrder.void_reason.
 */
class BankTransaction extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'bank_account_id', 'transaction_number', 'transaction_date',
        'description', 'reference', 'source_type', 'source_id', 'debit_sgd',
        'credit_sgd', 'is_reconciled', 'reconciled_at', 'is_voided', 'void_reason',
        'voided_at', 'created_by_user_id',
        // A foreign-currency account's line in its own currency (multi-currency).
        'debit_fx', 'credit_fx',
    ];

    protected $attributes = [
        'debit_sgd' => '0.00',
        'credit_sgd' => '0.00',
        'is_reconciled' => false,
        'is_voided' => false,
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'debit_sgd' => 'decimal:2',
        'credit_sgd' => 'decimal:2',
        'is_reconciled' => 'boolean',
        'reconciled_at' => 'datetime',
        'is_voided' => 'boolean',
        'voided_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
