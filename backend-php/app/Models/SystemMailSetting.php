<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One system-level mailbox, keyed by what it is for. See the migration
 * for why there are two and why they are global rather than
 * company-scoped, and App\Services\Mailer for who sends through which.
 *
 * Write-only password: hidden from every read, encrypted at rest; the
 * API reports only whether one is on file (`password_set`).
 */
class SystemMailSetting extends Model
{
    public const PURPOSE_OTP = 'otp';

    public const PURPOSE_HELPDESK = 'helpdesk';

    public const PURPOSES = [self::PURPOSE_OTP, self::PURPOSE_HELPDESK];

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'purpose';

    protected $keyType = 'string';

    protected $fillable = [
        'purpose', 'host', 'port', 'username', 'password', 'use_tls', 'from_email', 'from_name',
        'imap_host', 'imap_port', 'imap_username', 'imap_password', 'imap_use_ssl',
    ];

    protected $hidden = ['password', 'imap_password'];

    protected $appends = ['password_set', 'imap_password_set'];

    protected $casts = [
        'port' => 'integer',
        'use_tls' => 'boolean',
        'password' => 'encrypted',
        'imap_port' => 'integer',
        'imap_use_ssl' => 'boolean',
        'imap_password' => 'encrypted',
        'updated_at' => 'datetime',
    ];

    public function getPasswordSetAttribute(): bool
    {
        return (bool) $this->password;
    }

    public function getImapPasswordSetAttribute(): bool
    {
        return (bool) $this->imap_password;
    }

    /** Has this mailbox got enough to attempt an IMAP login? */
    public function isImapConfigured(): bool
    {
        return (bool) ($this->imap_host && $this->imap_username && $this->imap_password);
    }

    /** A row counts as configured once it can address a message: a host and a From. */
    public function isConfigured(): bool
    {
        return (bool) ($this->host && $this->from_email);
    }
}
