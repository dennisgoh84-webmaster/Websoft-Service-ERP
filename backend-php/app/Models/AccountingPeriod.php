<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One posting period (typically a calendar month) for a company.
 * Mirrors backend/app/models/periods.py's AccountingPeriod.
 *
 * NOT yet converted: Period management itself (create/close/reopen a
 * period, lock/unlock individual doc-type x operation cells, Year-End
 * Closing) -- see App\Services\Periods' class docblock. This model
 * exists purely so App\Services\Posting's guard can look a period up
 * correctly the day that management UI is converted; until then no
 * row is ever created, so the guard is always a no-op (matching
 * Python's own "opt-in protection: a date with no period defined is
 * unrestricted").
 */
class AccountingPeriod extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'company_id', 'fiscal_year', 'name', 'period_start', 'period_end',
        'status', 'closed_by_user_id', 'closed_at',
    ];

    protected $attributes = ['status' => self::STATUS_OPEN];

    protected $casts = [
        'fiscal_year' => 'integer',
        'period_start' => 'date',
        'period_end' => 'date',
        'closed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function locks(): HasMany
    {
        return $this->hasMany(PeriodLock::class, 'period_id');
    }
}
