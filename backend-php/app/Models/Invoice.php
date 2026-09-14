<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Single-line tax invoices (BILL-002: no approval required -- issued
 * directly in "outstanding" status). Mirrors
 * backend/app/models/billing.py's Invoice -- see that file's
 * docstring for the GST and GP-costing conventions.
 *
 * Money fields use 'decimal:2' (not 'float') because the service
 * layer computes with them via App\Support\Money -- see
 * docs/php-conversion-plan.md's Decimal/money handling convention.
 * Cast to float only at the JSON response boundary.
 */
class Invoice extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    public const TYPE_CONTRACT_ANNUAL = 'contract_annual';

    public const TYPE_EXCESS_USAGE = 'excess_usage';

    public const STATUS_OUTSTANDING = 'outstanding';

    public const STATUS_PARTIALLY_PAID = 'partially_paid';

    public const STATUS_PAID = 'paid';

    public const STATUS_WRITTEN_OFF = 'written_off';

    protected $fillable = [
        'company_id', 'customer_id', 'contract_id', 'excess_usage_record_id',
        'invoice_number', 'invoice_type', 'description', 'amount_sgd', 'tax_code',
        'gst_rate', 'gst_amount_sgd', 'total_amount_sgd', 'cost_sgd', 'due_date',
        'status', 'amount_paid_sgd', 'is_disputed', 'dispute_note',
    ];

    protected $casts = [
        'amount_sgd' => 'decimal:2',
        'gst_rate' => 'decimal:2',
        'gst_amount_sgd' => 'decimal:2',
        'total_amount_sgd' => 'decimal:2',
        'cost_sgd' => 'decimal:2',
        'amount_paid_sgd' => 'decimal:2',
        'due_date' => 'date',
        'is_disputed' => 'boolean',
        'issued_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CompanyIndividual::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** What is still owed on this invoice (never negative). */
    public function outstandingSgd(): Money
    {
        if ($this->status === self::STATUS_WRITTEN_OFF) {
            return Money::of(0);
        }
        $remaining = Money::of($this->total_amount_sgd)->minus(Money::of($this->amount_paid_sgd));

        return $remaining->toFloat() < 0 ? Money::of(0) : $remaining;
    }
}
