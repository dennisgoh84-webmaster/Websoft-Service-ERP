<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SRV-003/SRV-004: usage beyond contracted hours. Never auto-billed or
 * auto-absorbed -- requires a reviewer decision (Nico, or Cherish as
 * backup per SRV-011), with the decision and reason auditable. Mirrors
 * backend/app/models/contracts.py's ExcessUsageRecord.
 *
 * Rows are created by App\Services\ServiceRecordService::approveServiceRecord()
 * (SRV-003/004's split-at-approval logic); the treatment decision
 * itself (billable/goodwill/write-off, SRV-008/011/013) is not yet
 * exposed by a controller -- that's the Excess Usage module, still
 * pending (see docs/php-conversion-plan.md).
 */
class ExcessUsageRecord extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    public const TREATMENT_BILLABLE = 'billable';

    public const TREATMENT_APPROVED_NON_BILLABLE = 'approved_non_billable';

    public const TREATMENT_WARRANTY_GOODWILL = 'warranty_goodwill';

    public const TREATMENT_INTERNAL_WRITE_OFF = 'internal_write_off';

    public const TREATMENT_OTHER = 'other';

    protected $fillable = [
        'company_id', 'contract_id', 'service_record_id', 'excess_minutes',
        'treatment', 'reason', 'decided_by_user_id', 'decided_at', 'invoiced',
    ];

    protected $casts = [
        'excess_minutes' => 'integer',
        'decided_at' => 'datetime',
        'invoiced' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function isDecided(): bool
    {
        return $this->treatment !== null;
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function serviceRecord(): BelongsTo
    {
        return $this->belongsTo(ServiceRecord::class);
    }
}
