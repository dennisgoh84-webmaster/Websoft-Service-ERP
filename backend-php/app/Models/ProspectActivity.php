<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProspectActivity extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    // created_at comes from the database default; updated_at is set by
    // the controller on edit (and has a database default for inserts).
    public $timestamps = false;

    public const ACTIVITY_TYPE_CALL = 'call';

    public const ACTIVITY_TYPE_EMAIL = 'email';

    public const ACTIVITY_TYPE_MEETING = 'meeting';

    public const ACTIVITY_TYPE_NOTE = 'note';

    public const ACTIVITY_TYPE_FOLLOW_UP = 'follow_up';

    public const ACTIVITY_TYPE_PROPOSAL = 'proposal';

    public const ACTIVITY_TYPE_DEMO = 'demo';

    public const ACTIVITY_TYPE_NEGOTIATION = 'negotiation';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CANCELLED = 'cancelled';

    // Set only by the Void action, never picked as an ordinary status:
    // activities are voided, never deleted (Dennis, 2026-09-26).
    public const STATUS_VOID = 'void';

    protected $fillable = [
        'company_id',
        'customer_id',
        'activity_type',
        'subject',
        'description',
        'activity_date',
        'status',
        'created_by_user_id',
        'last_edited_by_user_id',
        'prospect_id',
    ];

    protected $casts = [
        'activity_date' => 'datetime',
        'voided_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CompanyIndividual::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function lastEditedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_edited_by_user_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }
}
