<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CompanyIndividual Management -- the Customer/Supplier master record.
 * Mirrors backend/app/models/company_individuals.py's CompanyIndividual
 * exactly (field set, comments and all); see that file for the full
 * design rationale (Odoo Contacts card field mapping, PDPA consent,
 * data-expiry archival, the merged customer/supplier role flags, etc.).
 */
class CompanyIndividual extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    protected $table = 'company_individuals';

    public $timestamps = false;

    public const TYPE_INDIVIDUAL = 'individual';

    public const TYPE_COMPANY = 'company';

    protected $fillable = [
        'company_id', 'customer_type', 'name', 'customer_group_id',
        'legacy_customer_code', 'contact_person', 'uen', 'gst_registration_no',
        'billing_email', 'phone', 'mobile', 'website',
        'address_line1', 'address_line2', 'address_city', 'address_state',
        'address_postal_code', 'address_country', 'tags', 'industry_code',
        'exclude_auto_sent', 'terms_and_conditions', 'memo', 'billing_notes',
        'payment_terms_days', 'is_customer', 'is_supplier',
        'po_approval_limit_sgd', 'credit_note_approval_limit_sgd',
        'pdpa_consent_given', 'pdpa_consent_at', 'pdpa_agreement_document',
        'data_expiry_date', 'is_archived', 'archived_at', 'is_active',
    ];

    protected $casts = [
        'exclude_auto_sent' => 'boolean',
        'is_customer' => 'boolean',
        'is_supplier' => 'boolean',
        'pdpa_consent_given' => 'boolean',
        'pdpa_consent_at' => 'datetime',
        'data_expiry_date' => 'date',
        'is_archived' => 'boolean',
        'archived_at' => 'datetime',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'payment_terms_days' => 'integer',
        // Per-party approval limits (Dennis, 2026-09-26); null = owner approves.
        'po_approval_limit_sgd' => 'float',
        'credit_note_approval_limit_sgd' => 'float',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CompanyIndividualGroup::class, 'customer_group_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'customer_id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class, 'customer_id');
    }
}
