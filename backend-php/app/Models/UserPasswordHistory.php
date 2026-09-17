<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPasswordHistory extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['user_id', 'hashed_password', 'set_at'];

    protected $casts = ['set_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
