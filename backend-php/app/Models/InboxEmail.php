<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One email read from the helpdesk mailbox for the Email Inbox -- see
 * App\Services\EmailInbox. Never deleted: it is logged (an Incident, and
 * a Job Order when one could be opened) or dismissed with a reason.
 */
class InboxEmail extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    public const STATUS_NEW = 'new';

    public const STATUS_LOGGED = 'logged';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUSES = [self::STATUS_NEW, self::STATUS_LOGGED, self::STATUS_DISMISSED];

    protected $fillable = [
        'mailbox', 'uid_validity', 'uid', 'message_id', 'from_name', 'from_email', 'subject', 'received_at',
        'body_text', 'attachment_names', 'status', 'company_id', 'incident_id', 'job_order_id',
        'handled_by_user_id', 'handled_at', 'dismiss_reason',
    ];

    protected $casts = [
        'uid_validity' => 'integer',
        'uid' => 'integer',
        'received_at' => 'datetime',
        'handled_at' => 'datetime',
        'fetched_at' => 'datetime',
        'attachment_names' => 'array',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by_user_id');
    }
}
