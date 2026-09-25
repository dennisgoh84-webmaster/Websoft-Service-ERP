<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Exceptions\ContractRuleViolation;
use App\Http\Controllers\Api\Concerns\SendsExports;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\CompanyIndividual;
use App\Models\Contract;
use App\Models\JobOrder;
use App\Models\JobOrderImplementationTask;
use App\Models\JobOrderProduct;
use App\Models\Product;
use App\Models\ProjectMilestone;
use App\Models\ServiceRecord;
use App\Models\User;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\JobOrderImplementationTaskService;
use App\Services\Numbering;
use App\Services\ReportsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Job Orders (formerly "Helpdesk Tickets") -- Service Operations.
 * Mirrors backend/app/routers/job_orders.py.
 *
 * NOT yet converted from the Python router: CSV/Excel export.
 */
class JobOrderController extends Controller
{
    use SendsExports;

    /** @var array<int, string> */
    private const EXPORT_FIELDS = [
        'job_order_number', 'subject', 'job_order_type', 'customer_name', 'priority', 'status',
        'is_urgent', 'assigned_to', 'due_date', 'created_at',
    ];

    private const MODULE = 'service_operations';

    // Sales Manager (Cherish) or Owner -- budget overrun approval (7.1)
    // and milestone completion (7.3).
    private const OVERRUN_APPROVAL_ROLES = [User::ROLE_SALES_MANAGER, User::ROLE_OWNER];

    // Default project milestones template -- created automatically
    // when a PROJECT-type Job Order is opened.
    private const PROJECT_MILESTONE_TEMPLATE = [
        ['installation', 'Installation', 0],
        ['training', 'Training', 1],
        ['repeat_training', 'Repeat Training', 2],
        ['handover', 'Handover', 3],
        ['completion_signoff', 'Completion Sign-off', 4],
    ];

    private function jobOrderOrFail(string $companyId, string $jobOrderId): JobOrder
    {
        $jobOrder = JobOrder::with(['milestones', 'products.product', 'implementationTasks'])->find($jobOrderId);
        if (! $jobOrder || $jobOrder->company_id !== $companyId) {
            throw new ApiException(404, 'Job order not found');
        }

        return $jobOrder;
    }

    /**
     * Check if a PROJECT-type Job Order has exceeded its contract's
     * hours or cost. Returns null for SUPPORT-type or no-contract JOs
     * (matching the Python version exactly).
     */
    private function computeBudgetOverrun(JobOrder $jobOrder): ?array
    {
        if ($jobOrder->job_order_type !== JobOrder::TYPE_PROJECT || ! $jobOrder->contract_id) {
            return null;
        }
        $contract = Contract::find($jobOrder->contract_id);
        if (! $contract) {
            return null;
        }

        // Sum approved Service Record minutes for this Job Order.
        $totalMinutes = (int) ServiceRecord::where('job_order_id', $jobOrder->id)
            ->where('status', ServiceRecord::STATUS_APPROVED)
            ->sum('rounded_minutes');

        // Compute cost: use the contract's blended rate (value / hours).
        $contractedHours = $contract->contracted_minutes > 0 ? $contract->contracted_minutes / 60 : 0;
        $contractValue = (float) $contract->contract_value_sgd;
        $blendedRate = $contractedHours > 0 ? $contractValue / $contractedHours : 0;
        $consumedHours = $totalMinutes / 60;
        $consumedCost = $consumedHours * $blendedRate;

        return [
            'is_over_hours' => $contract->contracted_minutes > 0 && $totalMinutes > $contract->contracted_minutes,
            'is_over_cost' => $contractValue > 0 && $consumedCost > $contractValue,
            'consumed_minutes' => $totalMinutes,
            'contracted_minutes' => $contract->contracted_minutes,
            'consumed_cost_sgd' => round($consumedCost, 2),
            'contract_value_sgd' => round($contractValue, 2),
        ];
    }

    private function present(JobOrder $jobOrder): array
    {
        return [
            'id' => $jobOrder->id,
            'job_order_number' => $jobOrder->job_order_number,
            'customer_id' => $jobOrder->customer_id,
            'contract_id' => $jobOrder->contract_id,
            'subject' => $jobOrder->subject,
            'job_order_type' => $jobOrder->job_order_type,
            'billing_classification' => $jobOrder->billing_classification,
            'priority' => $jobOrder->priority,
            'status' => $jobOrder->status,
            'is_urgent' => $jobOrder->is_urgent,
            'assigned_to_user_id' => $jobOrder->assigned_to_user_id,
            'due_date' => optional($jobOrder->due_date)->toDateString(),
            'void_reason' => $jobOrder->void_reason,
            'budget_overrun_approved' => $jobOrder->budget_overrun_approved,
            'budget_overrun_approved_by' => $jobOrder->budget_overrun_approved_by,
            'budget_overrun_approved_at' => $jobOrder->budget_overrun_approved_at,
            'created_at' => $jobOrder->created_at,
            'closed_at' => $jobOrder->closed_at,
            'milestones' => $jobOrder->milestones->map(fn ($m) => $this->presentMilestone($m))->values(),
            'budget_overrun' => $this->computeBudgetOverrun($jobOrder),
            // NEW FEATURE (not a Python->PHP conversion) -- see
            // docs/backlog.md / docs/planned-work.md: "Job Order - To
            // allow choosing of multiple Products and Template to
            // import according to Product".
            'products' => $jobOrder->products->map(fn (JobOrderProduct $p) => [
                'product_id' => $p->product_id,
                'product_name' => $p->product?->name,
            ])->values(),
            'implementation_tasks' => $jobOrder->implementationTasks->map(fn ($t) => $this->presentImplementationTask($t))->values(),
        ];
    }

    private function presentImplementationTask(JobOrderImplementationTask $t): array
    {
        return [
            'id' => $t->id,
            'job_order_id' => $t->job_order_id,
            'source_product_id' => $t->source_product_id,
            'task_name' => $t->task_name,
            'description' => $t->description,
            'sort_order' => $t->sort_order,
            'status' => $t->status,
            'completed_by_user_id' => $t->completed_by_user_id,
            'completed_at' => optional($t->completed_at)->toIso8601String(),
        ];
    }

    private function presentMilestone(ProjectMilestone $m): array
    {
        return [
            'id' => $m->id,
            'job_order_id' => $m->job_order_id,
            'milestone_type' => $m->milestone_type,
            'label' => $m->label,
            'sort_order' => $m->sort_order,
            'planned_start' => optional($m->planned_start)->toDateString(),
            'planned_end' => optional($m->planned_end)->toDateString(),
            'actual_start' => optional($m->actual_start)->toDateString(),
            'actual_end' => optional($m->actual_end)->toDateString(),
            'assigned_user_id' => $m->assigned_user_id,
            'status' => $m->status,
            'notes' => $m->notes,
            'created_at' => $m->created_at,
        ];
    }

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $data = $request->validate([
            'customer_id' => 'required|uuid',
            'contract_id' => 'required|uuid',
            'subject' => 'required|string',
            'job_order_type' => 'sometimes|in:support,project',
            // SRV-020: what this Job Order's approved time is.
            'billing_classification' => 'sometimes|in:contract,billable,non_billable',
            'priority' => 'sometimes|in:low,normal,high,critical',
            'due_date' => 'sometimes|nullable|date',
            'is_urgent' => 'sometimes|boolean',
            // NEW FEATURE (not a Python->PHP conversion) -- see
            // docs/backlog.md / docs/planned-work.md.
            'product_ids' => 'sometimes|array',
            'product_ids.*' => 'uuid',
        ]);
        $data['job_order_type'] ??= JobOrder::TYPE_SUPPORT;
        $data['billing_classification'] ??= JobOrder::BILLING_CONTRACT;
        $data['priority'] ??= JobOrder::PRIORITY_NORMAL;
        $data['is_urgent'] ??= false;
        $productIds = $data['product_ids'] ?? [];
        unset($data['product_ids']);

        // NEW FEATURE (not a Python->PHP conversion): "Service
        // Contract - ... When opening of Job Orders, have to check
        // according to this [shared-hours] company list included."
        // The Job Order's customer must be the contract's own primary
        // customer, or on that contract's independent shared-hours
        // list -- see App\Models\Contract::allowsCustomer().
        $contract = Contract::find($data['contract_id']);
        if (! $contract || $contract->company_id !== $user->company_id) {
            throw new ApiException(404, 'Contract not found');
        }
        if (! $contract->allowsCustomer($data['customer_id'])) {
            throw new ApiException(422, 'This company / individual is not the contract\'s own customer and is not on its shared-hours list.');
        }

        try {
            $jobOrder = DB::transaction(function () use ($data, $user, $productIds) {
                $jobOrder = JobOrder::create(array_merge($data, [
                    'company_id' => $user->company_id,
                    'job_order_number' => Numbering::next($user->company_id, 'job_order'),
                ]));

                // Auto-create milestone schedule template for PROJECT type.
                if ($data['job_order_type'] === JobOrder::TYPE_PROJECT) {
                    foreach (self::PROJECT_MILESTONE_TEMPLATE as [$mtype, $label, $sortOrder]) {
                        ProjectMilestone::create([
                            'job_order_id' => $jobOrder->id,
                            'milestone_type' => $mtype,
                            'label' => $label,
                            'sort_order' => $sortOrder,
                        ]);
                    }
                }

                foreach ($productIds as $productId) {
                    $product = Product::find($productId);
                    if ($product === null || $product->company_id !== $user->company_id) {
                        throw new ContractRuleViolation('Unknown product selected on this job order.');
                    }
                    JobOrderProduct::create(['job_order_id' => $jobOrder->id, 'product_id' => $productId]);
                }
                // "Selecting that Product on a Job Order copies the
                // checklist onto the Job Order as tasks" -- see
                // App\Services\JobOrderImplementationTaskService.
                JobOrderImplementationTaskService::importFromProducts($jobOrder, $productIds);

                return $jobOrder;
            });
        } catch (ContractRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->present($this->jobOrderOrFail($user->company_id, $jobOrder->id)));
    }

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->filtered($user->company_id, $request)->map(fn ($jo) => $this->present($jo))->values();
    }

    /**
     * The list the screen shows, honouring every filter -- shared with
     * the exports so an Export button always returns what is on
     * screen. (Python's export helper takes four of these five
     * filters and drops `job_order_type`, so a filtered screen there
     * can export rows it is not showing; sharing one filter here means
     * that cannot happen.)
     *
     * @return Collection<int, JobOrder>
     */
    private function filtered(string $companyId, Request $request)
    {
        $query = JobOrder::with(['milestones', 'products.product', 'implementationTasks'])
            ->where('company_id', $companyId);
        foreach (['status', 'priority', 'customer_id', 'contract_id', 'job_order_type'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }

        return $query->orderByDesc('created_at')->get();
    }

    /** @return array<int, array<string, mixed>> */
    private function exportRows(string $companyId, Request $request): array
    {
        $orders = $this->filtered($companyId, $request);
        $customerNames = CompanyIndividual::where('company_id', $companyId)->pluck('name', 'id');
        $assigneeNames = ReportsService::userNames($orders->pluck('assigned_to_user_id'));

        return $orders->map(fn (JobOrder $jo) => [
            'job_order_number' => $jo->job_order_number,
            'subject' => $jo->subject,
            'job_order_type' => $jo->job_order_type,
            'customer_name' => $customerNames[$jo->customer_id] ?? '',
            'priority' => $jo->priority,
            'status' => $jo->status,
            'is_urgent' => $jo->is_urgent,
            'assigned_to' => $jo->assigned_to_user_id === null
                ? ''
                : ($assigneeNames[$jo->assigned_to_user_id] ?? ''),
            'due_date' => optional($jo->due_date)->toDateString() ?? '',
            'created_at' => optional($jo->created_at)->toIso8601String(),
        ])->all();
    }

    public function exportCsv(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->csvResponse(
            self::EXPORT_FIELDS, $this->exportRows($user->company_id, $request), 'job-orders.csv'
        );
    }

    public function exportExcel(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->xlsxResponse(
            self::EXPORT_FIELDS, $this->exportRows($user->company_id, $request), 'Job Orders', 'job-orders.xlsx'
        );
    }

    public function show(Request $request, string $jobOrderId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return response()->json($this->present($this->jobOrderOrFail($user->company_id, $jobOrderId)));
    }

    public function assign(Request $request, string $jobOrderId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $jobOrder = $this->jobOrderOrFail($user->company_id, $jobOrderId);
        if (in_array($jobOrder->status, [JobOrder::STATUS_CLOSED, JobOrder::STATUS_VOID], true)) {
            throw new ApiException(409, "Job order is {$jobOrder->status}; reopen it first.");
        }
        $data = $request->validate(['assigned_to_user_id' => 'required|uuid']);

        $jobOrder->assigned_to_user_id = $data['assigned_to_user_id'];
        $jobOrder->status = JobOrder::STATUS_ASSIGNED;
        $jobOrder->save();

        return response()->json($this->present($jobOrder->fresh('milestones')));
    }

    /**
     * Manual due date, set/changed by whoever opens the Job Order
     * (Sales/Coordinator) after discussion with Support -- see
     * App\Models\JobOrder's docblock.
     */
    public function setDueDate(Request $request, string $jobOrderId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $jobOrder = $this->jobOrderOrFail($user->company_id, $jobOrderId);
        if (in_array($jobOrder->status, [JobOrder::STATUS_CLOSED, JobOrder::STATUS_VOID], true)) {
            throw new ApiException(409, "Job order is {$jobOrder->status}; reopen it first.");
        }
        $data = $request->validate(['due_date' => 'sometimes|nullable|date']);

        $oldDueDate = optional($jobOrder->due_date)->toDateString();
        $jobOrder->due_date = $data['due_date'] ?? null;
        Audit::record(
            'job_order', $jobOrder->id, 'due_date_set', $user->id,
            oldValue: ['due_date' => $oldDueDate], newValue: ['due_date' => $data['due_date'] ?? null],
        );
        $jobOrder->save();

        return response()->json($this->present($jobOrder->fresh('milestones')));
    }

    /**
     * "Option to also tick Job Order as Urgent then Rates will X1.5" --
     * manual, toggleable any time before the work is approved; the
     * suggested-deduction-minutes multiplier itself lives in Service
     * Records (not yet converted).
     */
    public function setUrgent(Request $request, string $jobOrderId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $jobOrder = $this->jobOrderOrFail($user->company_id, $jobOrderId);
        $data = $request->validate(['is_urgent' => 'required|boolean']);

        $oldValue = $jobOrder->is_urgent;
        $jobOrder->is_urgent = $data['is_urgent'];
        Audit::record('job_order', $jobOrder->id, 'urgent_set', $user->id, oldValue: ['is_urgent' => $oldValue], newValue: ['is_urgent' => $jobOrder->is_urgent]);
        $jobOrder->save();

        return response()->json($this->present($jobOrder->fresh('milestones')));
    }

    /**
     * SRV-020: the billing classification can be corrected while the
     * Job Order is still open, since it decides what every Service
     * Record approved from then on becomes. Records already approved
     * keep the outcome they were given -- changing this never rewrites
     * a contract deduction. Audited, like Urgent.
     */
    public function setBillingClassification(Request $request, string $jobOrderId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $jobOrder = $this->jobOrderOrFail($user->company_id, $jobOrderId);
        $data = $request->validate(['billing_classification' => 'required|in:contract,billable,non_billable']);
        if (! in_array($jobOrder->status, [JobOrder::STATUS_OPEN, JobOrder::STATUS_ASSIGNED], true)) {
            throw new ApiException(409, 'Only an open or assigned Job Order can have its billing classification changed.');
        }

        $oldValue = $jobOrder->billing_classification;
        $jobOrder->billing_classification = $data['billing_classification'];
        Audit::record(
            'job_order', $jobOrder->id, 'billing_classification_set', $user->id,
            oldValue: ['billing_classification' => $oldValue],
            newValue: ['billing_classification' => $jobOrder->billing_classification],
        );
        $jobOrder->save();

        return response()->json($this->present($jobOrder->fresh('milestones')));
    }

    /**
     * VOID is the one remaining manual terminal state, for a Job Order
     * that should never have been raised at all (duplicate, raised in
     * error) -- distinct from a normally completed job. A reason is
     * required for audit.
     */
    public function void(Request $request, string $jobOrderId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $jobOrder = $this->jobOrderOrFail($user->company_id, $jobOrderId);
        if (! in_array($jobOrder->status, [JobOrder::STATUS_OPEN, JobOrder::STATUS_ASSIGNED], true)) {
            throw new ApiException(409, "Job order is already {$jobOrder->status}; nothing to void.");
        }
        $data = $request->validate(['reason' => 'required|string|min:1|max:500']);

        $oldStatus = $jobOrder->status;
        $jobOrder->status = JobOrder::STATUS_VOID;
        $jobOrder->void_reason = $data['reason'];
        Audit::record(
            'job_order', $jobOrder->id, 'voided', $user->id,
            oldValue: ['status' => $oldStatus], newValue: ['status' => $jobOrder->status, 'void_reason' => $data['reason']],
        );
        $jobOrder->save();

        return response()->json($this->present($jobOrder->fresh('milestones')));
    }

    /**
     * Correct an accidental auto-close or void without editing the
     * database directly -- owner-only, mirroring the Accounting Period
     * reopen pattern.
     */
    public function reopen(Request $request, string $jobOrderId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');
        if ($user->role !== User::ROLE_OWNER) {
            throw new ApiException(403, 'Only the owner can reopen a job order.');
        }

        $jobOrder = $this->jobOrderOrFail($user->company_id, $jobOrderId);
        if (! in_array($jobOrder->status, [JobOrder::STATUS_CLOSED, JobOrder::STATUS_VOID], true)) {
            throw new ApiException(409, 'Job order is not closed or void.');
        }

        $oldStatus = $jobOrder->status;
        $jobOrder->status = $jobOrder->assigned_to_user_id ? JobOrder::STATUS_ASSIGNED : JobOrder::STATUS_OPEN;
        $jobOrder->closed_at = null;
        $jobOrder->void_reason = null;
        Audit::record('job_order', $jobOrder->id, 'reopened', $user->id, oldValue: ['status' => $oldStatus], newValue: ['status' => $jobOrder->status]);
        $jobOrder->save();

        return response()->json($this->present($jobOrder->fresh('milestones')));
    }

    /** Sales Manager (Cherish) or Owner approves continuation past budget overrun on a PROJECT-type Job Order (7.1). */
    public function approveOverrun(Request $request, string $jobOrderId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');
        if (! in_array($user->role, self::OVERRUN_APPROVAL_ROLES, true)) {
            throw new ApiException(403, 'Only Sales Manager or Owner can approve budget overrun.');
        }

        $jobOrder = $this->jobOrderOrFail($user->company_id, $jobOrderId);
        if ($jobOrder->job_order_type !== JobOrder::TYPE_PROJECT) {
            throw new ApiException(409, 'Budget overrun applies to PROJECT-type Job Orders only.');
        }
        if ($jobOrder->budget_overrun_approved) {
            throw new ApiException(409, 'Budget overrun already approved.');
        }

        $jobOrder->budget_overrun_approved = true;
        $jobOrder->budget_overrun_approved_by = $user->id;
        $jobOrder->budget_overrun_approved_at = Carbon::now();
        Audit::record('job_order', $jobOrder->id, 'budget_overrun_approved', $user->id, newValue: ['budget_overrun_approved' => true]);
        $jobOrder->save();

        return response()->json($this->present($jobOrder->fresh('milestones')));
    }

    // ---- Project Milestones (PROJECT-type Job Orders) -----------------

    public function addMilestone(Request $request, string $jobOrderId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $jobOrder = $this->jobOrderOrFail($user->company_id, $jobOrderId);
        if ($jobOrder->job_order_type !== JobOrder::TYPE_PROJECT) {
            throw new ApiException(409, 'Milestones are only for PROJECT-type Job Orders.');
        }
        $data = $request->validate([
            'milestone_type' => 'required|in:installation,training,repeat_training,handover,completion_signoff',
            'label' => 'required|string',
            'sort_order' => 'sometimes|integer',
            'planned_start' => 'sometimes|nullable|date',
            'planned_end' => 'sometimes|nullable|date',
            'assigned_user_id' => 'sometimes|nullable|uuid',
            'notes' => 'sometimes|nullable|string',
        ]);
        $data['sort_order'] ??= 0;

        $milestone = ProjectMilestone::create(array_merge($data, ['job_order_id' => $jobOrder->id]));
        Audit::record('project_milestone', $milestone->id, 'created', $user->id, newValue: ['milestone_type' => $data['milestone_type'], 'label' => $data['label']]);

        return response()->json($this->presentMilestone($milestone->fresh()));
    }

    public function listMilestones(Request $request, string $jobOrderId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $jobOrder = $this->jobOrderOrFail($user->company_id, $jobOrderId);

        return ProjectMilestone::where('job_order_id', $jobOrder->id)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($m) => $this->presentMilestone($m))
            ->values();
    }

    /**
     * Update dates, status, assignment or notes on a milestone.
     * Milestone completion (7.3): only Sales Manager or Owner can set
     * status to COMPLETED.
     */
    public function updateMilestone(Request $request, string $jobOrderId, string $milestoneId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $jobOrder = $this->jobOrderOrFail($user->company_id, $jobOrderId);
        $milestone = ProjectMilestone::find($milestoneId);
        if (! $milestone || $milestone->job_order_id !== $jobOrder->id) {
            throw new ApiException(404, 'Milestone not found');
        }

        $fields = $request->validate([
            'label' => 'sometimes|nullable|string',
            'sort_order' => 'sometimes|nullable|integer',
            'planned_start' => 'sometimes|nullable|date',
            'planned_end' => 'sometimes|nullable|date',
            'actual_start' => 'sometimes|nullable|date',
            'actual_end' => 'sometimes|nullable|date',
            'assigned_user_id' => 'sometimes|nullable|uuid',
            'status' => 'sometimes|nullable|in:pending,in_progress,completed,skipped',
            'notes' => 'sometimes|nullable|string',
        ]);

        // 7.3: gate COMPLETED status to Sales Manager / Owner.
        if (
            ($fields['status'] ?? null) === ProjectMilestone::STATUS_COMPLETED
            && $milestone->status !== ProjectMilestone::STATUS_COMPLETED
            && ! in_array($user->role, self::OVERRUN_APPROVAL_ROLES, true)
        ) {
            throw new ApiException(403, 'Only Sales Manager or Owner can mark a milestone as Completed.');
        }

        $oldValues = [];
        $newValues = [];
        foreach ($fields as $field => $value) {
            if ($value === null) {
                continue;
            }
            $oldValues[$field] = $milestone->{$field};
            $milestone->{$field} = $value;
            $newValues[$field] = $value;
        }

        if ($newValues) {
            Audit::record('project_milestone', $milestone->id, 'updated', $user->id, oldValue: $oldValues, newValue: $newValues);
        }
        $milestone->save();

        return response()->json($this->presentMilestone($milestone->fresh()));
    }

    public function deleteMilestone(Request $request, string $jobOrderId, string $milestoneId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $jobOrder = $this->jobOrderOrFail($user->company_id, $jobOrderId);
        $milestone = ProjectMilestone::find($milestoneId);
        if (! $milestone || $milestone->job_order_id !== $jobOrder->id) {
            throw new ApiException(404, 'Milestone not found');
        }

        Audit::record('project_milestone', $milestone->id, 'deleted', $user->id, oldValue: ['milestone_type' => $milestone->milestone_type, 'label' => $milestone->label]);
        $milestone->delete();

        return response()->noContent();
    }

    /**
     * Re-initialize the default milestone template on a PROJECT Job
     * Order. Only works when it currently has zero milestones.
     */
    public function initMilestoneTemplate(Request $request, string $jobOrderId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $jobOrder = $this->jobOrderOrFail($user->company_id, $jobOrderId);
        if ($jobOrder->job_order_type !== JobOrder::TYPE_PROJECT) {
            throw new ApiException(409, 'Only PROJECT-type Job Orders support milestones.');
        }
        if (ProjectMilestone::where('job_order_id', $jobOrder->id)->count() > 0) {
            throw new ApiException(409, 'Milestones already exist; delete them first to re-init.');
        }

        $milestones = collect(self::PROJECT_MILESTONE_TEMPLATE)->map(
            fn ($t) => ProjectMilestone::create(['job_order_id' => $jobOrder->id, 'milestone_type' => $t[0], 'label' => $t[1], 'sort_order' => $t[2]])
        );

        return $milestones->map(fn ($m) => $this->presentMilestone($m))->values();
    }

    // ---- Multi-Product selection + Job Implementation Template import ----
    // NEW FEATURE (not a Python->PHP conversion) -- see
    // docs/backlog.md / docs/planned-work.md.

    /**
     * Adds products to an already-created Job Order (on top of
     * whatever was selected at creation) and imports each newly-added
     * product's Job Implementation Template tasks. Already-linked
     * products are ignored, not duplicated -- see
     * App\Services\JobOrderImplementationTaskService::importFromProducts()
     * for the dedupe rule.
     */
    public function addProducts(Request $request, string $jobOrderId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $jobOrder = $this->jobOrderOrFail($user->company_id, $jobOrderId);
        $data = $request->validate([
            'product_ids' => 'required|array|min:1',
            'product_ids.*' => 'uuid',
        ]);

        $alreadyLinked = JobOrderProduct::where('job_order_id', $jobOrder->id)->pluck('product_id')->all();
        $newProductIds = array_values(array_diff($data['product_ids'], $alreadyLinked));

        DB::transaction(function () use ($jobOrder, $newProductIds, $user) {
            foreach ($newProductIds as $productId) {
                $product = Product::find($productId);
                if ($product === null || $product->company_id !== $user->company_id) {
                    throw new ApiException(404, 'Unknown product');
                }
                JobOrderProduct::create(['job_order_id' => $jobOrder->id, 'product_id' => $productId]);
            }
            $added = JobOrderImplementationTaskService::importFromProducts($jobOrder, $newProductIds);
            if (! empty($newProductIds)) {
                Audit::record(
                    'job_order', $jobOrder->id, 'products_added', $user->id,
                    details: count($newProductIds).' product(s), '.$added.' implementation task(s) imported',
                    newValue: ['product_ids' => $newProductIds],
                );
            }
        });

        return response()->json($this->present($this->jobOrderOrFail($user->company_id, $jobOrder->id)));
    }

    private function implementationTaskOrFail(JobOrder $jobOrder, string $taskId): JobOrderImplementationTask
    {
        $task = JobOrderImplementationTask::find($taskId);
        if (! $task || $task->job_order_id !== $jobOrder->id) {
            throw new ApiException(404, 'Implementation task not found');
        }

        return $task;
    }

    /** 7.3-style gate, mirroring milestone completion: Sales Manager or Owner only. */
    public function completeImplementationTask(Request $request, string $jobOrderId, string $taskId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');
        if (! JobOrderImplementationTaskService::canComplete($user)) {
            throw new ApiException(403, 'Only Sales Manager or Owner can mark an implementation task as Completed.');
        }

        $jobOrder = $this->jobOrderOrFail($user->company_id, $jobOrderId);
        $task = $this->implementationTaskOrFail($jobOrder, $taskId);

        JobOrderImplementationTaskService::markCompleted($task, $user);
        Audit::record('job_order_implementation_task', $task->id, 'completed', $user->id, details: $task->task_name);

        return response()->json($this->presentImplementationTask($task->fresh()));
    }

    public function reopenImplementationTask(Request $request, string $jobOrderId, string $taskId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $jobOrder = $this->jobOrderOrFail($user->company_id, $jobOrderId);
        $task = $this->implementationTaskOrFail($jobOrder, $taskId);

        JobOrderImplementationTaskService::markPending($task);
        Audit::record('job_order_implementation_task', $task->id, 'reopened', $user->id, details: $task->task_name);

        return response()->json($this->presentImplementationTask($task->fresh()));
    }
}
