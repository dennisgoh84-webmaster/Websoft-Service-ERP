<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mirrors backend/app/models/groups.py's Group -- see that file's
 * docstring for the Group Authority design (a staff member holds
 * exactly one Group per company they work in).
 */
class Group extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    protected $table = 'groups';

    public $timestamps = false;

    protected $fillable = ['company_id', 'name', 'description'];

    protected $casts = ['created_at' => 'datetime'];

    public function authorities(): HasMany
    {
        return $this->hasMany(GroupModuleAuthority::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
