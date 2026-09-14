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
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'write_off_approval_threshold_sgd' => 'decimal:2',
        'credit_note_approval_threshold_sgd' => 'decimal:2',
        'po_approval_threshold_sgd' => 'decimal:2',
    ];
}
