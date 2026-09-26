<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Customer Helpdesk Portal -- the customer-side login (PORTAL-001..004).
 * Mirrors backend/app/models/portal.py's PortalUser exactly.
 *
 * A PortalUser is one *person* at a customer -- exactly one per Contact
 * (PORTAL-001) -- and lives in its own table, never in staff `users`
 * (PORTAL-003). Its token carries purpose="portal" and staff endpoints
 * reject it; see App\Http\Middleware\AuthenticatePortal (the PHP
 * equivalent of backend/app/core/deps.py's get_current_portal_user),
 * which is the whole security boundary. Staff enable access from the
 * Company/Individual page (App\Http\Controllers\Api\CompanyIndividualController,
 * mirroring backend/app/routers/company_individuals.py); the customer
 * signs in at /portal (App\Http\Controllers\Api\PortalAuthController /
 * PortalController, mirroring backend/app/routers/portal.py).
 *
 * Design: docs/customer-portal-design.md §3.
 */
class PortalUser extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'portal_users';

    public $timestamps = false;

    // Mirrors the DB column defaults (see the migration) so a freshly
    // constructed, not-yet-saved/refreshed PortalUser behaves the same
    // as one just reloaded from the database.
    protected $attributes = [
        'is_active' => true,
        'must_change_password' => true,
        'failed_attempts' => 0,
    ];

    protected $fillable = [
        'company_id', 'contact_id', 'email', 'hashed_password', 'is_active',
        'must_change_password', 'failed_attempts', 'locked_until', 'last_login_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'must_change_password' => 'boolean',
        'failed_attempts' => 'integer',
        'locked_until' => 'datetime',
        'last_login_at' => 'datetime',
        'ai_data_consent_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected $hidden = ['hashed_password'];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
