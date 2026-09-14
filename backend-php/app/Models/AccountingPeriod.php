<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One posting period (typically a calendar month) for a company.
 * Mirrors backend/app/models/periods.py's AccountingPeriod. Managed
 * via App\Http\Controllers\Api\PeriodController /
 * App\Services\Periods (create/close/reopen, per-doc-type x
 * per-operation lock matrix, Year-End Closing).
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
