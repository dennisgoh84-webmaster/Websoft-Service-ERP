<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Binds an authority to a document type, optionally above a value
 * threshold. Mirrors backend/app/models/approvals.py's ApprovalRule.
 *
 * A null `threshold_amount` means every document of this type needs
 * this approval, whatever its value; a figure means at or above that
 * amount. `priority` orders which rules are evaluated first.
 */
class ApprovalRule extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = [
        'authority_id', 'entity_type', 'threshold_amount', 'priority', 'is_active',
    ];

    protected $attributes = ['priority' => 0, 'is_active' => true];

    protected $casts = [
        'threshold_amount' => 'decimal:2',
        'priority' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function authority(): BelongsTo
    {
        return $this->belongsTo(ApprovalAuthority::class, 'authority_id');
    }
}
