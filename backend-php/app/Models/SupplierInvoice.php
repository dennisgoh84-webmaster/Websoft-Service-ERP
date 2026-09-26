<?php

namespace App\Models;

use App\Models\Concerns\HasCurrency;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bill received from a supplier ("Payment Voucher" is the money
 * going out against it -- App\Models\SupplierPayment). Mirrors
 * backend/app/models/payables.py's SupplierInvoice.
 *
 * `expense_account_id` is the GL posting's expense account
 * (gl-posting-design.md §4.2) -- optional; App\Services\Posting
 * defaults to 5000 Cost of services when unset.
 */
class SupplierInvoice extends Model
{
    use HasCurrency;
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    public const MATCH_NOT_MATCHED = 'not_matched';

    public const MATCH_MATCHED = 'matched'; // agrees with the PO -> auto-approved (PUR-003)

    public const MATCH_EXCEPTION = 'exception'; // disagrees -- resolution is open item 4.5

    public const STATUS_AWAITING_MATCH = 'awaiting_match';

    public const STATUS_EXCEPTION = 'exception';

    public const STATUS_APPROVED = 'approved'; // cleared for payment

    public const STATUS_PARTIALLY_PAID = 'partially_paid';

    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'company_id', 'supplier_id', 'purchase_order_id', 'expense_account_id', 'bill_number',
        'supplier_invoice_no', 'invoice_date', 'due_date', 'description',
        'amount_sgd', 'tax_code', 'gst_rate', 'gst_amount_sgd', 'total_amount_sgd', 'amount_paid_sgd',
        'match_status', 'match_note', 'status',
        'currency_code', 'exchange_rate', 'amount_fx', 'gst_amount_fx', 'total_amount_fx', 'amount_paid_fx',
    ];

    protected $attributes = [
        'match_status' => self::MATCH_NOT_MATCHED,
        'status' => self::STATUS_AWAITING_MATCH,
        'gst_amount_sgd' => '0.00',
        'total_amount_sgd' => '0.00',
        'amount_paid_sgd' => '0.00',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'amount_sgd' => 'decimal:2',
        'gst_amount_sgd' => 'decimal:2',
        'total_amount_sgd' => 'decimal:2',
        'amount_paid_sgd' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(CompanyIndividual::class, 'supplier_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function outstandingSgd(): Money
    {
        $remaining = Money::of($this->total_amount_sgd ?? 0)->minus(Money::of($this->amount_paid_sgd ?? 0));

        return $remaining->toFloat() < 0 ? Money::of(0) : $remaining;
    }

    /** What is still owed in the bill's own currency (never negative). */
    public function outstandingFx(): Money
    {
        $remaining = $this->fx('total_amount')->minus($this->fx('amount_paid'));

        return $remaining->toFloat() < 0 ? Money::of(0) : $remaining;
    }
}
