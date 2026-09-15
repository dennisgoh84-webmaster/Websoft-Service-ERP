<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One approver's answer on one request. One per approver per request. */
class ApprovalDecision extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const VALUES = [self::APPROVED, self::REJECTED];

    protected $fillable = ['request_id', 'user_id', 'decision', 'comment'];

    protected $casts = ['decided_at' => 'datetime'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'request_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
