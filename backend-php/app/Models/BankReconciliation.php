<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One completed Bank Reconciliation session. Mirrors
 * backend/app/models/treasury.py's BankReconciliation.
 *
 * A permanent history -- never edited, never deleted. The two balance
 * figures are snapshots taken at save time, because the Bank Book keeps
 * moving afterwards: they record what actually reconciled, not what a
 * live query would say today.
 */
class BankReconciliation extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'bank_account_id', 'statement_date', 'statement_balance_sgd',
        'ledger_balance_sgd', 'difference_sgd', 'note', 'reconciled_by_user_id',
    ];

    protected $casts = [
        'statement_date' => 'date',
        'statement_balance_sgd' => 'decimal:2',
        'ledger_balance_sgd' => 'decimal:2',
        'difference_sgd' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
