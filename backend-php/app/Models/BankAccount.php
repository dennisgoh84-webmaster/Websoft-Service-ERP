<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of the company's own bank accounts -- the Bank Master File.
 * Mirrors backend/app/models/treasury.py's BankAccount. The Bank Book
 * (BankTransaction) is deliberately its own ledger, separate from the
 * General Ledger's Journal Vouchers -- see that model's docstring.
 */
class BankAccount extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'bank_name', 'account_name', 'account_number', 'branch',
        'swift_code', 'currency_code', 'gl_account_id', 'opening_balance_sgd',
        'opening_balance_date', 'is_active',
    ];

    protected $attributes = [
        'currency_code' => 'SGD',
        'opening_balance_sgd' => '0.00',
        'is_active' => true,
    ];

    protected $casts = [
        'opening_balance_sgd' => 'decimal:2',
        'opening_balance_date' => 'date',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'gl_account_id');
    }
}
