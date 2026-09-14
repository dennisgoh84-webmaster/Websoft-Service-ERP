<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SRV-005: unused hours forfeited at expiry, kept visible for
 * reporting/audit -- never deleted, never converted to credit.
 * Mirrors backend/app/models/contracts.py's ExpiredHoursRecord.
 */
class ExpiredHoursRecord extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['contract_id', 'expired_minutes'];

    protected $casts = ['expired_minutes' => 'integer', 'recorded_at' => 'datetime'];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
