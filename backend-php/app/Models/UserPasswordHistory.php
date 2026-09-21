<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPasswordHistory extends Model
{
    use HasUuidPrimaryKey;

    // The migration names the table singular (user_password_history),
    // not Eloquent's default plural guess -- without this, every query
    // silently 42P01s against a table that doesn't exist.
    protected $table = 'user_password_history';

    public $timestamps = false;

    protected $fillable = ['user_id', 'hashed_password', 'set_at'];

    protected $casts = ['set_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
