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

    // Sales roles (Dennis, 2026-09-26: "sales staff roles... sales
    // supervisor and manager"). Sales Manager is the existing role above.
    public const ROLE_SALES_SUPERVISOR = 'sales_supervisor';

    public const ROLE_SALES_STAFF = 'sales_staff';

    public const ROLES = [
        self::ROLE_OWNER, self::ROLE_SERVICE_LEAD, self::ROLE_SALES_MANAGER, self::ROLE_SALES_SUPERVISOR,
        self::ROLE_SALES_STAFF, self::ROLE_SUPPORT_ENGINEER, self::ROLE_FINANCE,
    ];

    /**
     * Pragmatic default (docs/open-business-decisions.md #45): no team
     * structure exists, so a Sales Supervisor sees every prospect like
     * the Sales Manager; everyone else sees their own.
     */
    public function seesAllProspects(): bool
    {
        return in_array($this->role, [self::ROLE_OWNER, self::ROLE_SALES_MANAGER, self::ROLE_SALES_SUPERVISOR], true);
    }

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'username', 'email', 'hashed_password', 'full_name', 'role',
        'photo', 'must_change_password', 'force_password_change_on_login', 'is_active', 'phone',
    ];

    protected $hidden = ['hashed_password'];

    protected $casts = [
        'must_change_password' => 'boolean',
        'force_password_change_on_login' => 'boolean',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        // Deliberately NOT in $fillable -- see the migration's docblock.
        // Set exactly once, by AuthController::acknowledgeAiConsent().
        'ai_data_consent_at' => 'datetime',
    ];

    /** Has this user acknowledged the AI Assistant's PDPA notice yet? */
    public function needsAiDataConsent(): bool
    {
        return $this->ai_data_consent_at === null;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function companyAccess(): HasMany
    {
        return $this->hasMany(UserCompanyAccess::class);
    }

    public function passwordHistory(): HasMany
    {
        return $this->hasMany(UserPasswordHistory::class);
    }
}
