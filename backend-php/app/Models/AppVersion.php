<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

class AppVersion extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['version', 'release_date', 'changelog', 'deployment_notes', 'is_public'];

    protected $casts = [
        'is_public' => 'boolean',
        'release_date' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    public function scopeOrderByVersionDesc($query)
    {
        return $query->orderByDesc('release_date');
    }
}
