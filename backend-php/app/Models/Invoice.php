<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tax invoices (BILL-002: no approval required -- issued directly in
 * "outstanding" status). Mirrors
 * backend/app/models/billing.py's Invoice -- see that file's
 * docstring for the GST and GP-costing conventions.
 *
 * Historically every invoice was single-line: one amount, auto-issued
 * from a contract activation or an excess-usage decision. Since
 * 2026-09-15 a manually raised Sales Invoice may instead carry
 * `lines` (see App\Models\InvoiceLine). Lines are OPTIONAL and the
 * header totals are authoritative either way, so nothing that reads an
 * invoice needs to know which kind it is holding.
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

    /**
     * A manually raised Sales Invoice -- the only type that carries
     * lines, and the only one that can move stock. See
     * App\Services\BillingService::issueSalesInvoice().
     */
    public const TYPE_SALES = 'sales';

    public const STATUS_OUTSTANDING = 'outstanding';

    public const STATUS_PARTIALLY_PAID = 'partially_paid';

    public const STATUS_PAID = 'paid';

    public const STATUS_WRITTEN_OFF = 'written_off';

    // Mirrors the DB column defaults (see the migration) so a freshly
    // constructed, not-yet-saved/refreshed Invoice (e.g. the one
    // BillingService returns) behaves the same as one just reloaded
    // from the database -- outstandingSgd() below would otherwise see
    // a null amount_paid_sgd rather than zero.
    protected $attributes = [
        'status' => self::STATUS_OUTSTANDING,
        'amount_paid_sgd' => '0.00',
        'pre_migration_paid_sgd' => '0.00',
        'is_disputed' => false,
    ];

    protected $fillable = [
        'company_id', 'customer_id', 'contract_id', 'excess_usage_record_id',
        'invoice_number', 'invoice_type', 'description', 'amount_sgd', 'tax_code',
        'gst_rate', 'gst_amount_sgd', 'total_amount_sgd', 'cost_sgd', 'due_date',
        'status', 'amount_paid_sgd', 'is_disputed', 'dispute_note',
        // Data Migration (docs/data-migration.md) -- zero/null on every
        // invoice raised in this system.
        'pre_migration_paid_sgd', 'migrated_at',
        'prospect_id',
    ];

    protected $casts = [
        'amount_sgd' => 'decimal:2',
        'gst_rate' => 'decimal:2',
        'gst_amount_sgd' => 'decimal:2',
        'total_amount_sgd' => 'decimal:2',
        'cost_sgd' => 'decimal:2',
        'amount_paid_sgd' => 'decimal:2',
        'pre_migration_paid_sgd' => 'decimal:2',
        'migrated_at' => 'datetime',
        'due_date' => 'date',
        'is_disputed' => 'boolean',
        'issued_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * An invoice raised from a contract that a prospect's quotation
     * became is tied back to that prospect (Dennis, 2026-09-26), however
     * it was issued -- contract activation, an excess-usage decision, or
     * by hand against the contract.
     */
    protected static function booted(): void
    {
        static::creating(function (Invoice $invoice) {
            if ($invoice->prospect_id === null && $invoice->contract_id !== null) {
                $invoice->prospect_id = Contract::find($invoice->contract_id)?->quotation?->prospect_id;
            }
        });
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CompanyIndividual::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** Empty on every auto-issued invoice -- see the class docblock. */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('line_no');
    }

    /** What is still owed on this invoice (never negative). */
    public function outstandingSgd(): Money
    {
        if ($this->status === self::STATUS_WRITTEN_OFF) {
            return Money::of(0);
        }
        $remaining = Money::of($this->total_amount_sgd ?? 0)->minus(Money::of($this->amount_paid_sgd ?? 0));

        return $remaining->toFloat() < 0 ? Money::of(0) : $remaining;
    }
}
