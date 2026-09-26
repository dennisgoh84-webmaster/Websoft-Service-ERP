<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The single row the host upgrade agent keeps fresh on every heartbeat. */
class UpgradeAgentState extends Model
{
    public const AGENT_OFFLINE_AFTER_MINUTES = 5;

    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'upgrade_agent_state';

    protected $fillable = [
        'id', 'current_sha', 'current_subject', 'current_committed_at', 'current_version',
        'remote_sha', 'remote_subject', 'remote_committed_at', 'remote_version', 'commits_behind',
        'agent_host', 'last_heartbeat_at',
    ];

    protected $casts = [
        'current_committed_at' => 'datetime',
        'remote_committed_at' => 'datetime',
        'commits_behind' => 'integer',
        'last_heartbeat_at' => 'datetime',
    ];

    public static function singleton(): self
    {
        return self::firstOrCreate(['id' => 1]);
    }
}
