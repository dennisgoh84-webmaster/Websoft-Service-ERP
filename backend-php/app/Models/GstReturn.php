<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One saved GST Calculation (IRAS Form 5) for a locked accounting
 * period -- see App\Services\GstReturns. Never edited after it is made;
 * a recalculation adds the next version and supersedes this one. The
 * only later marks are its submission to IRAS and, if that submission is
 * revised, who opened the revision, when and why.
 */
class GstReturn extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    public const STATUS_CURRENT = 'current';

    public const STATUS_SUPERSEDED = 'superseded';

    /** What each box is, as Form 5 words it. */
    public const BOXES = [
        1 => 'Total value of standard-rated supplies',
        2 => 'Total value of zero-rated supplies',
        3 => 'Total value of exempt supplies',
        4 => 'Total value of (1) + (2) + (3)',
        5 => 'Total value of taxable purchases',
        6 => 'Output tax due',
        7 => 'Input tax and refunds claimed',
        8 => 'Net GST to be paid to / (claimed from) IRAS',
        9 => 'Total value of goods imported under import GST suspension schemes',
        10 => 'Tourist refund claimed',
        11 => 'Bad debt relief claims',
        12 => 'Pre-registration claims',
        13 => 'Revenue for the accounting period',
    ];

    protected $fillable = [
        'company_id', 'accounting_period_id', 'period_start', 'period_end', 'version', 'status',
        'box_1_sgd', 'box_2_sgd', 'box_3_sgd', 'box_4_sgd', 'box_5_sgd', 'box_6_sgd', 'box_7_sgd',
        'box_8_sgd', 'box_9_sgd', 'box_10_sgd', 'box_11_sgd', 'box_12_sgd', 'box_13_sgd',
        'output_document_count', 'input_document_count', 'calculated_by_user_id', 'superseded_at',
        'submitted_by_user_id', 'submitted_at',
        'revision_opened_by_user_id', 'revision_opened_at', 'revision_reason', 'revises_gst_return_id',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'calculated_at' => 'datetime',
        'superseded_at' => 'datetime',
        'submitted_at' => 'datetime',
        'revision_opened_at' => 'datetime',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    public function calculatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calculated_by_user_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function revisionOpenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revision_opened_by_user_id');
    }

    /** The submitted return this version revises, if it is a revision. */
    public function revises(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revises_gst_return_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GstReturnLine::class);
    }
}
