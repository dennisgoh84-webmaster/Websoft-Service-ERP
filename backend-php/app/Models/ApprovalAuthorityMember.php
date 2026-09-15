<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person assigned to an approval authority. */
class ApprovalAuthorityMember extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['authority_id', 'user_id'];

    protected $casts = ['added_at' => 'datetime'];

    public function authority(): BelongsTo
    {
        return $this->belongsTo(ApprovalAuthority::class, 'authority_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
