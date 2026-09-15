<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One document awaiting one rule's approval. Mirrors
 * backend/app/models/approvals.py's ApprovalRequest.
 *
 * NEVER DELETED, and neither are its decisions: a resolved request
 * keeps the full record of who decided what and when. planned-work #4
 * calls this out specifically, because today's Service Record approval
 * screen drops an item off the list the moment it is acted on, leaving
 * no after-the-fact view of what was approved versus rejected.
 */
class ApprovalRequest extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'company_id', 'entity_type', 'entity_id', 'rule_id', 'authority_id',
        'status', 'requested_by_user_id', 'resolved_at',
    ];

    protected $attributes = ['status' => self::STATUS_PENDING];

    protected $casts = [
        'requested_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class, 'request_id');
    }

    public function authority(): BelongsTo
    {
        return $this->belongsTo(ApprovalAuthority::class, 'authority_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ApprovalRule::class, 'rule_id');
    }
}
