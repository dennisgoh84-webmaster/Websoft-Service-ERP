<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One voucher in the general ledger. Mirrors
 * backend/app/models/accounting.py's JournalEntry -- see that class's
 * docstring: double entry is enforced (a voucher cannot post unless
 * its debits equal its credits), and a posted entry is immutable --
 * corrections are made by REVERSING it, never by editing or deleting.
 */
class JournalEntry extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    public const TYPE_JOURNAL = 'journal';

    public const TYPE_RECEIPT = 'receipt';

    public const TYPE_PAYMENT = 'payment';

    public const TYPE_SALES_INVOICE = 'sales_invoice';

    public const TYPE_PURCHASE_INVOICE = 'purchase_invoice';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'company_id', 'voucher_number', 'voucher_type', 'entry_date', 'narration',
        'status', 'source_type', 'source_id', 'reverses_entry_id',
        'created_by_user_id', 'posted_by_user_id', 'posted_at',
    ];

    protected $attributes = [
        'voucher_type' => self::TYPE_JOURNAL,
        'status' => self::STATUS_DRAFT,
    ];

    protected $casts = [
        'entry_date' => 'date',
        'posted_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'entry_id');
    }

    public function totalDebit(): Money
    {
        return $this->lines->reduce(fn (Money $carry, JournalLine $l) => $carry->plus(Money::of($l->debit_sgd)), Money::of(0));
    }

    public function totalCredit(): Money
    {
        return $this->lines->reduce(fn (Money $carry, JournalLine $l) => $carry->plus(Money::of($l->credit_sgd)), Money::of(0));
    }

    public function isBalanced(): bool
    {
        $debit = $this->totalDebit();
        $credit = $this->totalCredit();

        return $debit->toFloat() === $credit->toFloat() && $debit->toFloat() > 0;
    }
}
