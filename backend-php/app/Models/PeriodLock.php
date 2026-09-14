<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cell of the period x document-type x operation lock matrix.
 * Mirrors backend/app/models/periods.py's PeriodLock -- see
 * App\Models\AccountingPeriod's docblock for why no row exists yet.
 */
class PeriodLock extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['period_id', 'doc_type', 'operation', 'is_locked', 'locked_by_user_id', 'locked_at'];

    protected $attributes = ['is_locked' => false];

    protected $casts = [
        'is_locked' => 'boolean',
        'locked_at' => 'datetime',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'period_id');
    }
}
