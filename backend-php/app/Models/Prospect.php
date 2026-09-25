<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One sales opportunity for a Company / Individual (Dennis, 2026-09-26):
 * activities are logged against it, it can carry several quotations,
 * and invoices raised from those quotations' contracts point back to it.
 * A company can have more than one prospect over time.
 */
class Prospect extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    // created_at/updated_at come from the database default (UTC), as on
    // most models here; Eloquent would write the app's Singapore clock
    // into a UTC column, eight hours ahead. updated_at is set on edit.
    public $timestamps = false;

    // Pragmatic default (docs/open-business-decisions.md #45): no
    // pipeline stages were given, so a prospect is open until the
    // salesperson marks it won or lost.
    public const STATUS_OPEN = 'open';

    public const STATUS_WON = 'won';

    public const STATUS_LOST = 'lost';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_WON, self::STATUS_LOST];

    /**
     * Quotations counted as "quoted": the ones actually put to the
     * customer. Drafts and internal approval steps are not yet quoted;
     * a quotation sent back for revision is superseded by its revision;
     * rejected and expired ones no longer stand.
     */
    public const QUOTED_STATUSES = [Quotation::STATUS_SENT, Quotation::STATUS_ACCEPTED];

    protected $fillable = [
        'company_id', 'prospect_number', 'customer_id', 'title', 'source', 'status',
        'estimated_value_sgd', 'expected_close_date', 'salesperson_user_id', 'notes',
        'lost_reason', 'created_by_user_id', 'last_edited_by_user_id',
    ];

    protected $attributes = [
        'status' => self::STATUS_OPEN,
    ];

    protected $casts = [
        'estimated_value_sgd' => 'decimal:2',
        'expected_close_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CompanyIndividual::class, 'customer_id');
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ProspectActivity::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * What a user may see. Sales Staff (and anyone else outside sales
     * management) see only the prospects they are the salesperson on or
     * created; the owner, Sales Manager and Sales Supervisor see all.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $query->where('company_id', $user->company_id);
        if (! $user->seesAllProspects()) {
            $query->where(fn (Builder $q) => $q->where('salesperson_user_id', $user->id)->orWhere('created_by_user_id', $user->id));
        }

        return $query;
    }

    public function isVisibleTo(User $user): bool
    {
        return $this->company_id === $user->company_id
            && ($user->seesAllProspects() || $this->salesperson_user_id === $user->id || $this->created_by_user_id === $user->id);
    }

    /** @return array{estimated_value_sgd: ?float, quoted_amount_sgd: float, billed_amount_sgd: float, paid_amount_sgd: float, outstanding_amount_sgd: float} */
    public function amounts(): array
    {
        $quoted = Money::of(0);
        foreach ($this->quotations()->whereIn('status', self::QUOTED_STATUSES)->pluck('total_amount_sgd') as $total) {
            $quoted = $quoted->plus(Money::of($total ?? 0));
        }
        $billed = Money::of(0);
        $paid = Money::of(0);
        $outstanding = Money::of(0);
        foreach ($this->invoices()->get() as $invoice) {
            $billed = $billed->plus(Money::of($invoice->total_amount_sgd ?? 0));
            $paid = $paid->plus(Money::of($invoice->amount_paid_sgd ?? 0));
            $outstanding = $outstanding->plus($invoice->outstandingSgd());
        }

        return [
            'estimated_value_sgd' => $this->estimated_value_sgd === null ? null : (float) $this->estimated_value_sgd,
            'quoted_amount_sgd' => $quoted->toFloat(),
            'billed_amount_sgd' => $billed->toFloat(),
            'paid_amount_sgd' => $paid->toFloat(),
            'outstanding_amount_sgd' => $outstanding->toFloat(),
        ];
    }
}
