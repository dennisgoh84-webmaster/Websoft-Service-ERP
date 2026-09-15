<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One salesperson's commission for one month. Mirrors
 * backend/app/models/commissions.py's CommissionPayout.
 *
 * The Commission report says what commission *would be*; this turns
 * that into a record that can be submitted, approved, paid, or clawed
 * back. `amount_sgd` is positive for an EARNING and negative for a
 * CLAWBACK -- a clawback is a new negative row, never an edit to the
 * earning it reverses, so both stay on record (CLAUDE.md forbids
 * deleting financial records).
 */
class CommissionPayout extends Model
{
    use HasUuidPrimaryKey;

    public const TYPE_EARNING = 'earning';

    public const TYPE_CLAWBACK = 'clawback';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING_APPROVAL,
        self::STATUS_APPROVED,
        self::STATUS_PAID,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'company_id', 'payout_number', 'payout_type', 'status', 'sales_staff_id',
        'period_month', 'period_start', 'period_end', 'amount_sgd', 'rate_percent',
        'clawback_invoice_id', 'clawback_reason', 'submitted_by_user_id', 'submitted_at',
        'approved_by_user_id', 'approved_at', 'paid_date', 'paid_reference',
        'paid_by_user_id', 'notes',
    ];

    protected $attributes = [
        'payout_type' => self::TYPE_EARNING,
        'status' => self::STATUS_DRAFT,
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'paid_date' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'amount_sgd' => 'decimal:2',
        'rate_percent' => 'decimal:2',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function salesStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_staff_id');
    }

    public function clawbackInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'clawback_invoice_id');
    }
}
