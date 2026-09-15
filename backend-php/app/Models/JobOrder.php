<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Job Orders (formerly "Helpdesk Tickets") -- Service Operations.
 * Mirrors backend/app/models/job_orders.py's JobOrder exactly -- see
 * that file's docstring for the status model (auto-close from Service
 * Record approval isn't wired up yet -- see
 * docs/php-conversion-plan.md). No SLA targets exist and none are
 * planned (Dennis, 2026-09-15: removed outright, not deferred).
 *
 * SRV-020 (2026-09-15): `billing_classification` says what an approved
 * Service Record's time on this Job Order is -- CONTRACT (the linked
 * contract's balance decides deduction vs. Excess Usage, the original
 * and default behaviour), BILLABLE (chargeable outside the contract's
 * hour pool: nothing is deducted, the time is billed through the Job
 * Order's own billing) or NON_BILLABLE (internal / goodwill: nothing
 * deducted, nothing billed). Staff never pick this per record.
 */
class JobOrder extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    public const STATUS_OPEN = 'open';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_VOID = 'void';

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_CRITICAL = 'critical';

    public const TYPE_SUPPORT = 'support';

    public const TYPE_PROJECT = 'project';

    public const BILLING_CONTRACT = 'contract';

    public const BILLING_BILLABLE = 'billable';

    public const BILLING_NON_BILLABLE = 'non_billable';

    public const BILLING_CLASSIFICATIONS = [self::BILLING_CONTRACT, self::BILLING_BILLABLE, self::BILLING_NON_BILLABLE];

    protected $fillable = [
        'company_id', 'customer_id', 'contract_id', 'job_order_number', 'subject',
        'job_order_type', 'billing_classification', 'priority', 'status', 'assigned_to_user_id', 'due_date',
        'is_urgent', 'budget_overrun_approved', 'budget_overrun_approved_by',
        'budget_overrun_approved_at', 'void_reason', 'closed_at',
    ];

    protected $casts = [
        'due_date' => 'date',
        'is_urgent' => 'boolean',
        'budget_overrun_approved' => 'boolean',
        'budget_overrun_approved_at' => 'datetime',
        'created_at' => 'datetime',
        'closed_at' => 'datetime',
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

    public function milestones(): HasMany
    {
        return $this->hasMany(ProjectMilestone::class)->orderBy('sort_order');
    }

    public function serviceRecords(): HasMany
    {
        return $this->hasMany(ServiceRecord::class);
    }

    /**
     * NEW FEATURE (not a Python->PHP conversion -- see
     * docs/backlog.md / docs/planned-work.md): "Job Order - To allow
     * choosing of multiple Products". See App\Models\JobOrderProduct.
     */
    public function products(): HasMany
    {
        return $this->hasMany(JobOrderProduct::class);
    }

    /** The checklist copied on from each selected product's Job Implementation Template. */
    public function implementationTasks(): HasMany
    {
        return $this->hasMany(JobOrderImplementationTask::class)->orderBy('sort_order');
    }
}
