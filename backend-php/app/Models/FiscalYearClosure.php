<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Record of a Year-End Close: every period in `fiscal_year` was
 * already closed, and `closing_journal_entry_id` is the one voucher
 * that zeroed Revenue/Expense into `retained_earnings_account_id`.
 * Mirrors backend/app/models/periods.py's FiscalYearClosure -- never
 * deleted; if the close needs to be undone, the closing journal entry
 * itself is reversed (App\Services\Ledger::reverseEntry()), which
 * stays visible in the ledger and Event Logs rather than erasing this
 * record.
 */
class FiscalYearClosure extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'fiscal_year', 'retained_earnings_account_id',
        'closing_journal_entry_id', 'closed_by_user_id', 'closed_at',
    ];

    protected $casts = [
        'fiscal_year' => 'integer',
        'closed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function retainedEarningsAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'retained_earnings_account_id');
    }

    public function closingJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'closing_journal_entry_id');
    }
}
