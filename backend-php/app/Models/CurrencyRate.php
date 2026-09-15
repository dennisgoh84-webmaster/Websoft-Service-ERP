<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A currency's rate to the company's base currency (SGD) as at a given
 * date. Mirrors backend/app/models/treasury.py's CurrencyRate.
 *
 * Setup/reference data ONLY: nothing in the app converts an amount
 * using these rates -- the whole system is single-currency (SGD) per
 * CLAUDE.md's approved architecture. Multi-currency remains open item
 * 4b.5 in docs/open-business-decisions.md.
 */
class CurrencyRate extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['company_id', 'currency_code', 'rate_to_base', 'effective_date', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected $casts = [
        'rate_to_base' => 'decimal:6',
        'effective_date' => 'date',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
