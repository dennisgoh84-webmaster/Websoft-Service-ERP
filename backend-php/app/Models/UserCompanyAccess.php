<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which companies a staff member may work in, and their Group in each
 * one (multi-company). Mirrors backend/app/models/core.py's
 * UserCompanyAccess -- see that file's docstring for the full design
 * rationale (a Group per company, not one global Group).
 */
class UserCompanyAccess extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'user_company_access';

    public $timestamps = false;

    protected $fillable = ['user_id', 'company_id', 'group_id'];

    protected $casts = ['created_at' => 'datetime'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
