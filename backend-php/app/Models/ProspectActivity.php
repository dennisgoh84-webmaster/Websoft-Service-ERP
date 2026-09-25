<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProspectActivity extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    // As on Prospect: the database fills created_at/updated_at in UTC.
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
}
