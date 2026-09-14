<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One side of a voucher: an account, and either a debit or a credit
 * -- never both. Mirrors
 * backend/app/models/accounting.py's JournalLine.
 */
class JournalLine extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['entry_id', 'account_id', 'debit_sgd', 'credit_sgd', 'description'];

    protected $attributes = ['debit_sgd' => '0.00', 'credit_sgd' => '0.00'];

    protected $casts = [
        'debit_sgd' => 'decimal:2',
        'credit_sgd' => 'decimal:2',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
