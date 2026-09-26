<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GST / tax codes. Mirrors backend/app/models/tax.py's TaxCode -- see
 * that file's docstring. Webmaster Consultancy is confirmed
 * GST-registered, standard-rated (code 'SR'); the rate lives here
 * rather than being hard-coded so a future rate change is a data
 * change, not a code change. Every Invoice stores the rate it was
 * actually raised at, not a pointer to the current rate.
 */
class TaxCode extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    public const DEFAULT_CODE = 'SR';

    // A supply code (on sales: SR / ZR / ES / OS) or a purchase code (on
    // supplier bills: TX / ZP / EP / OP / NR) -- 2026-09-26, when bills
    // started carrying a tax code "like Sales Invoice Logic".
    public const KIND_SUPPLY = 'supply';

    public const KIND_PURCHASE = 'purchase';

    public const KINDS = [self::KIND_SUPPLY, self::KIND_PURCHASE];

    /** The purchase code a bill gets when none is chosen: standard-rated. */
    public const DEFAULT_PURCHASE_CODE = 'TX';

    protected $fillable = ['company_id', 'code', 'name', 'rate_percent', 'is_active', 'kind'];

    protected $casts = [
        'rate_percent' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
