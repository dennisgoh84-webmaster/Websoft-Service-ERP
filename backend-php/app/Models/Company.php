<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A legal entity using the system, managed from Company Setup
 * (App\Http\Controllers\Api\CompanyController). Webmaster Consultancy
 * Pte Ltd is the first; CLAUDE.md's approved architecture anticipates
 * more, so every company-owned record (customers, contracts, job
 * orders, groups, users, audit entries) carries a `company_id` and is
 * filtered by the signed-in user's *active* company.
 *
 * Mirrors backend/app/models/core.py's Company.
 */
class Company extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = [
        'name', 'country', 'currency', 'timezone', 'logo', 'address',
        'gst_registration_no', 'phone', 'website', 'uen',
        'write_off_approval_threshold_sgd', 'credit_note_approval_threshold_sgd',
        'po_approval_threshold_sgd', 'is_active',
        // Financial year + this company's own outbound mailbox
        // (2026-09-15) -- see the migration for why the company mailbox
        // is separate from the system one with no fallback.
        'financial_year_start_month',
        'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password',
        'smtp_use_tls', 'smtp_from_email', 'smtp_from_name',
    ];

    /**
     * NEVER serialised. Company Setup returns the model directly, so
     * without this the SMTP password would be handed back on every
     * read. It is write-only: set it, never read it back.
     */
    protected $hidden = ['smtp_password'];

    // Money fields: the Postgres column is `numeric(12,2)` (set in the
    // migration) so on-disk storage is always exact -- never a float.
    // These three are stored config values only (never computed on),
    // so -- like Python's CompanyOut schema, which types them as plain
    // `float | None` -- they're cast straight to float here too, so
    // the JSON wire format matches (a bare number, not a numeric
    // string). A field a *service* computes with (rates, totals,
    // allocations) instead uses 'decimal:2' + App\Support\Money -- see
    // docs/php-conversion-plan.md's Decimal/money handling convention.
    protected $attributes = ['financial_year_start_month' => 7];

    protected $casts = [
        'is_active' => 'boolean',
        'financial_year_start_month' => 'integer',
        'smtp_port' => 'integer',
        'smtp_use_tls' => 'boolean',
        // Encrypted at rest: a stolen database dump does not hand over
        // the mail account.
        'smtp_password' => 'encrypted',
        'created_at' => 'datetime',
        'write_off_approval_threshold_sgd' => 'float',
        'credit_note_approval_threshold_sgd' => 'float',
        'po_approval_threshold_sgd' => 'float',
    ];
}
