<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * One issued OTP challenge, over email or WhatsApp (`channel`; see
 * WhatsAppSender and AuthController::availableOtpChannels()). Mirrors
 * backend/app/models/core.py's LoginOtp -- see that file's docstring
 * for the full design of everything except `channel`, which postdates
 * the Python backend (WhatsApp OTP was deferred there; see
 * docs/planned-work.md).
 */
class LoginOtp extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'login_otps';

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'portal_user_id', 'code_hash', 'purpose', 'channel',
        'attempts', 'expires_at', 'consumed_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
