<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * Central audit trail -- the data behind the Event Logs module. Mirrors
 * backend/app/models/core.py's AuditLogEntry; see that file's docstring
 * for the full design rationale (point-in-time actor snapshot, field-
 * level old/new value diffs, device/IP capture).
 */
class AuditLogEntry extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'audit_log_entries';

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'entity_type', 'entity_id', 'action', 'actor_user_id',
        'actor_name', 'reason', 'details', 'old_value', 'new_value',
        'ip_address', 'user_agent', 'device_id',
    ];

    protected $casts = ['at' => 'datetime'];
}
