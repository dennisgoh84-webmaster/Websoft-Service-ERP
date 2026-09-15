<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The commission rate one company's Commission report applies.
 * Mirrors backend/app/models/payments.py's CommissionSettings.
 *
 * One row per company, keyed by the company itself rather than by a
 * surrogate id, so a company can never end up with two competing
 * rates. Zero until an administrator sets one -- see
 * docs/open-business-decisions.md #34: the formula is confirmed, the
 * percentage is not something to invent.
 */
class CommissionSettings extends Model
{
    protected $table = 'commission_settings';

    protected $primaryKey = 'company_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public const CREATED_AT = null;

    protected $fillable = ['company_id', 'rate_percent'];

    protected $attributes = ['rate_percent' => '0.00'];

    protected $casts = [
        'rate_percent' => 'decimal:2',
        'updated_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
