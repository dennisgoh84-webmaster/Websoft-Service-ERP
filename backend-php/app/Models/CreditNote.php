<?php

namespace App\Models;

use App\Models\Concerns\HasCurrency;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A credit note against one Sales Invoice (BILL-003) -- see
 * App\Services\CreditNotes for the rules and the migration
 * 2026_09_30_004000 for what each column holds.
 */
class CreditNote extends Model
{
    use HasCurrency;
    use HasUuidPrimaryKey;

    public const STATUS_PENDING = 'pending_approval';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'credit_note_number', 'invoice_id', 'customer_id', 'reason',
        'amount_sgd', 'tax_code', 'gst_rate', 'gst_amount_sgd', 'total_amount_sgd', 'status',
        'raised_by_user_id', 'raised_at', 'decided_by_user_id', 'decided_at', 'decision_note', 'issued_at',
        'currency_code', 'exchange_rate', 'amount_fx', 'gst_amount_fx', 'total_amount_fx',
    ];

    protected $casts = [
        'amount_sgd' => 'decimal:2',
        'gst_rate' => 'decimal:2',
        'gst_amount_sgd' => 'decimal:2',
        'total_amount_sgd' => 'decimal:2',
        'raised_at' => 'datetime',
        'decided_at' => 'datetime',
        'issued_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CompanyIndividual::class, 'customer_id');
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /** The invoice lines it credits (2026-09-26); none when it credits an amount. */
    public function lines(): HasMany
    {
        return $this->hasMany(CreditNoteLine::class)->orderBy('line_no');
    }

    /** Where its money went: its invoice, another invoice, or a refund. */
    public function applications(): HasMany
    {
        return $this->hasMany(CreditNoteApplication::class);
    }

    /** Credit still on the customer's account, in the note's currency (issued notes only). */
    public function unappliedFx(): Money
    {
        if ($this->status !== self::STATUS_ISSUED) {
            return Money::of(0);
        }
        $used = $this->applications()->get()->reduce(fn (Money $c, CreditNoteApplication $a) => $c->plus(Money::of($a->amount_fx)), Money::of(0));
        $left = $this->fx('total_amount')->minus($used);

        return $left->toFloat() < 0 ? Money::of(0) : $left;
    }

    /** The same in SGD, at the note's rate. */
    public function unappliedSgd(): Money
    {
        if ($this->status !== self::STATUS_ISSUED) {
            return Money::of(0);
        }
        $used = $this->applications()->get()->reduce(fn (Money $c, CreditNoteApplication $a) => $c->plus(Money::of($a->note_amount_sgd)), Money::of(0));
        $left = Money::of($this->total_amount_sgd)->minus($used);

        return $left->toFloat() < 0 ? Money::of(0) : $left;
    }
}
