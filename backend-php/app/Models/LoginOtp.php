<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * One issued email OTP challenge. Mirrors backend/app/models/core.py's
 * LoginOtp -- see that file's docstring for the full design (only email
 * is built; WhatsApp OTP is deferred, see docs/planned-work.md).
 */
class LoginOtp extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'login_otps';

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'portal_user_id', 'code_hash', 'purpose', 'attempts',
        'expires_at', 'consumed_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
