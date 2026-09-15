<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Service Contracts, implementing the confirmed SRV-001..018 rules
 * from docs/business-requirements.md. Mirrors
 * backend/app/models/contracts.py's Contract exactly -- see that
 * file's docstring for why contracted_minutes/consumed_minutes are
 * stored in minutes, not hours (avoids float rounding issues with the
 * SRV-007 15-minute rounding rule).
 *
 * NEW FEATURE FIELDS (not part of the Python->PHP conversion -- built
 * directly in backend-php per Dennis's request, see docs/backlog.md /
 * docs/planned-work.md):
 * - `quotation_id`: the Sales Quotation this contract came from -- a
 *   REAL link since 2026-09-15 (SALES-006), set by accepting a
 *   quotation or by hand. `quotation_reference` (+ its set_at/set_by
 *   pair) is the free-text stand-in it replaced: kept where a value
 *   was recorded, no longer entered. `renewalQuotations()` are the
 *   quotations raised FROM this contract as its renewal (Create
 *   renewal quotation); accepting one renews this contract.
 * - `sharedCustomers()` below: "Service Contract - To have selection
 *   of Sharing of Hours with multiple company". See
 *   App\Models\ContractSharedCustomer.
 */
class Contract extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    // SRV-002/012: hard minimum, no override.
    public const MINIMUM_CONTRACTED_HOURS = 10;

    // SRV-001.
    public const STANDARD_CONTRACT_MONTHS = 12;

    // SRV-007.
    public const HOUR_ROUNDING_MINUTES = 15;

    // SRV-016 (2 weeks).
    public const RENEWAL_BACKDATING_WINDOW_DAYS = 14;

    // SRV-014.
    public const PRE_EXPIRY_CHECK_LEAD_DAYS = 30;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXCEEDED = 'exceeded';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_RENEWED = 'renewed';

    public const KIND_SERVICE_SUPPORT = 'service_support';

    public const KIND_ANNUAL = 'annual';

    public const KIND_AD_HOC = 'ad_hoc';

    protected $fillable = [
        'company_id', 'customer_id', 'contract_number', 'status', 'contract_kind',
        'contracted_minutes', 'consumed_minutes', 'contract_value_sgd', 'hourly_rate_sgd',
        'sales_staff_id', 'start_date', 'end_date', 'renewed_from_contract_id', 'activated_at',
        // NEW FEATURE (not a Python->PHP conversion) -- see this
        // class's quotation_reference note below.
        'quotation_reference', 'quotation_reference_set_at', 'quotation_reference_set_by',
        'quotation_id',
    ];

    // Money fields use 'decimal:2' (not 'float') because the service
    // layer computes with them (blended rate, renewal value) via
    // App\Support\Money -- see docs/php-conversion-plan.md's Decimal/
    // money handling convention. Cast to float only at the JSON
    // response boundary (ContractController::present()).
    protected $casts = [
        'contracted_minutes' => 'integer',
        'consumed_minutes' => 'integer',
        'contract_value_sgd' => 'decimal:2',
        'hourly_rate_sgd' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'created_at' => 'datetime',
        'activated_at' => 'datetime',
        'quotation_reference_set_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CompanyIndividual::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(ContractProduct::class);
    }

    public function excessUsageRecords(): HasMany
    {
        return $this->hasMany(ExcessUsageRecord::class);
    }

    /** NEW FEATURE (not a Python->PHP conversion) -- see this class's docblock. */
    public function sharedCustomers(): HasMany
    {
        return $this->hasMany(ContractSharedCustomer::class);
    }

    /**
     * SRV-004-adjacent hour-sharing rule: a Job Order may be opened
     * against this contract for its own primary customer, OR for any
     * customer on its independent shared-hours list -- never any other
     * customer. See App\Http\Controllers\Api\JobOrderController::store().
     */
    public function allowsCustomer(string $customerId): bool
    {
        if ($this->customer_id === $customerId) {
            return true;
        }

        return $this->sharedCustomers()->where('customer_id', $customerId)->exists();
    }

    public function renewedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renewed_from_contract_id');
    }

    /** The Sales Quotation this contract came from (SALES-006). */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }

    /** Quotations raised from this contract as its renewal. */
    public function renewalQuotations(): HasMany
    {
        return $this->hasMany(Quotation::class, 'renews_contract_id');
    }

    public function remainingMinutes(): int
    {
        return max($this->contracted_minutes - $this->consumed_minutes, 0);
    }
}
