<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mirrors backend/app/models/core.py's User.
 *
 * RBAC is split across two independent axes (confirmed with Dennis,
 * 2026-09-10):
 * - `role` is a small fixed enum used ONLY for the specific
 *   named-responsibility rules already confirmed in the business rules
 *   (e.g. SRV-004/SRV-011: Nico, or Cherish as backup, decides excess
 *   usage; Dennis as owner). It does not drive general module access.
 * - Group Authority (see Group/GroupModuleAuthority) drives general
 *   per-module security. The group assignment lives on
 *   UserCompanyAccess, not on the user: exactly one Group **per
 *   company** the user works in, since Groups are themselves
 *   company-scoped.
 */
class User extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public const ROLE_OWNER = 'owner'; // Dennis

    public const ROLE_SERVICE_LEAD = 'service_lead'; // Nico

    public const ROLE_SALES_MANAGER = 'sales_manager'; // Cherish

    public const ROLE_SUPPORT_ENGINEER = 'support_engineer';

    public const ROLE_FINANCE = 'finance';

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'email', 'hashed_password', 'full_name', 'role',
        'photo', 'must_change_password', 'is_active',
    ];

    protected $hidden = ['hashed_password'];

    protected $casts = [
        'must_change_password' => 'boolean',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function companyAccess(): HasMany
    {
        return $this->hasMany(UserCompanyAccess::class);
    }
}
