<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sales Quotation. Mirrors backend/app/models/quotations.py's
 * Quotation exactly -- see that file's docstring for the confirmed
 * 2026-09-10 accept -> auto-Contract splitting rule this document
 * exists to record (App\Services\QuotationService::acceptQuotation).
 *
 * Money fields use 'decimal:2' (not 'float') because the service
 * layer computes with them via App\Support\Money -- see
 * docs/php-conversion-plan.md's Decimal/money handling convention.
 * Cast to float only at the JSON response boundary.
 */
class Quotation extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    // draft -> pending_approval -> approved -> sent -> accepted/rejected/
    // expired. BILL-006: the Sales Manager approves every quotation
    // before it goes to the customer (settled 2026-09-15 -- see the
    // 2026_09_30_000200 migration).
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SENT = 'sent';

    /** The customer asked for changes: a revision (a new quotation) follows. */
    public const STATUS_TO_REVISE = 'to_revise';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    /** BILL-006: Cherish (Sales Manager) approves every quotation; the owner can always stand in. */
    public const APPROVER_ROLES = [User::ROLE_SALES_MANAGER, User::ROLE_OWNER];

    protected $fillable = [
        'company_id', 'quotation_number', 'customer_id', 'quotation_date', 'valid_until',
        'status', 'notes', 'amount_sgd', 'tax_code', 'gst_rate', 'gst_amount_sgd',
        'total_amount_sgd', 'converted_contract_id', 'converted_annual_contract_id',
        'created_by_user_id',
        'submitted_at', 'submitted_by_user_id', 'approved_at', 'approved_by_user_id', 'sent_at', 'returned_reason',
        'renews_contract_id',
        'to_revise_at', 'revision_reason', 'revised_from_quotation_id',
        'prospect_id',
    ];

    // Mirrors the DB column defaults (see the migration) so a freshly
    // constructed, not-yet-saved/refreshed Quotation behaves the same
    // as one just reloaded from the database.
    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'amount_sgd' => '0.00',
        'tax_code' => 'SR',
        'gst_rate' => '0.00',
        'gst_amount_sgd' => '0.00',
        'total_amount_sgd' => '0.00',
    ];

    protected $casts = [
        'quotation_date' => 'date',
        'valid_until' => 'date',
        'amount_sgd' => 'decimal:2',
        'gst_rate' => 'decimal:2',
        'gst_amount_sgd' => 'decimal:2',
        'total_amount_sgd' => 'decimal:2',
        'created_at' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'sent_at' => 'datetime',
        'to_revise_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CompanyIndividual::class);
    }

    public function convertedContract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'converted_contract_id');
    }

    public function convertedAnnualContract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'converted_annual_contract_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class)->orderBy('line_no')->orderBy('id');
    }

    /** The contract this quotation was raised to renew, if any (SALES-006). */
    public function renewsContract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'renews_contract_id');
    }

    /** Still in play: not yet accepted, rejected or expired (to_revise is awaiting its revision). */
    public const OPEN_STATUSES = [
        self::STATUS_DRAFT, self::STATUS_PENDING_APPROVAL, self::STATUS_APPROVED, self::STATUS_SENT, self::STATUS_TO_REVISE,
    ];

    /** The quotation this one revises, if it was raised as a revision. */
    public function revisedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revised_from_quotation_id');
    }

    /** Revisions raised from this quotation (normally one). */
    public function revisions(): HasMany
    {
        return $this->hasMany(self::class, 'revised_from_quotation_id');
    }
}
