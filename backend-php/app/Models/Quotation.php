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

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'company_id', 'quotation_number', 'customer_id', 'quotation_date', 'valid_until',
        'status', 'notes', 'amount_sgd', 'tax_code', 'gst_rate', 'gst_amount_sgd',
        'total_amount_sgd', 'converted_contract_id', 'converted_annual_contract_id',
        'created_by_user_id',
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
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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
        return $this->hasMany(QuotationLine::class)->orderBy('id');
    }
}
