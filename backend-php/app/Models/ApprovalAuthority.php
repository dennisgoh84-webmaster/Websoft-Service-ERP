<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named approval authority -- "PO Approval", "Bank Authority for DBS
 * Current Account". Mirrors backend/app/models/approvals.py.
 *
 * DISTINCT FROM GROUP AUTHORITY, which is module-level CRUD access.
 * This says who may approve a particular kind of document, which is a
 * different question from who may open the screen (planned-work #4).
 */
class ApprovalAuthority extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    /** One approver suffices. */
    public const MODE_ANY_ONE = 'any_one';

    /** Every assigned member must approve. */
    public const MODE_ALL_MUST = 'all_must';

    public const MODES = [self::MODE_ANY_ONE, self::MODE_ALL_MUST];

    protected $fillable = [
        'company_id', 'name', 'description', 'mode', 'bank_account_id', 'is_active',
    ];

    protected $attributes = ['mode' => self::MODE_ANY_ONE, 'is_active' => true];

    protected $casts = ['is_active' => 'boolean', 'created_at' => 'datetime'];

    public function members(): HasMany
    {
        return $this->hasMany(ApprovalAuthorityMember::class, 'authority_id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(ApprovalRule::class, 'authority_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
