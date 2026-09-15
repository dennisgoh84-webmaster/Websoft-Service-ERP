<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\GroupModuleAuthority;
use App\Models\JobOrder;
use App\Models\OpsTask;
use App\Models\OpsTaskCategory;
use App\Models\SoftwareTask;
use App\Models\User;
use App\Services\Audit;
use App\Services\Authority;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * My Ops Dashboard. Mirrors backend/app/routers/ops_dashboard.py 1:1.
 *
 * A personal, freeform task board per staff member, plus a read-only
 * rollup of the real ERP work already assigned to them (confirmed
 * 2026-09-11: "auto-populate from real ERP data" alongside the freeform
 * tasks). The rollup introduces no model of its own -- it is filtered
 * reads of Job Orders and Software Tasks.
 *
 * VISIBILITY, confirmed 2026-09-11: everyone sees their own dashboard;
 * Owner, Service Lead and Sales Manager may also view AND edit someone
 * else's, for oversight. That is the same "manager-ish" role set
 * already used for Service Record approval and Excess Review.
 */
class OpsDashboardController extends Controller
{
    private const MODULE = 'ops_dashboard';

    /** @var array<int, string> */
    private const MANAGER_ROLES = [
        User::ROLE_OWNER,
        User::ROLE_SERVICE_LEAD,
        User::ROLE_SALES_MANAGER,
    ];

    public function show(Request $request)
    {
        $user = $this->at($request, GroupModuleAuthority::VIEW);
        $staff = $this->targetUser($user, $request->query('staff_id'));

        $categories = OpsTaskCategory::where('company_id', $user->company_id)
            ->where('owner_user_id', $staff->id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('created_at')->get();

        // Python skips the task query entirely when there are no
        // categories, since every task hangs off one.
        $tasks = $categories->isEmpty()
            ? collect()
            : OpsTask::where('company_id', $user->company_id)
                ->where('owner_user_id', $staff->id)
                ->where('is_active', true)
                ->orderBy('created_at')->get();

        $names = User::whereIn('id', $tasks->pluck('follow_up_staff_id')->filter()->unique())
            ->pluck('full_name', 'id');
        $byCategory = $tasks->groupBy('category_id');

        return response()->json([
            'staff_id' => $staff->id,
            'staff_name' => $staff->full_name,
            'can_view_others' => $this->isManager($user),
            'categories' => $categories->map(fn (OpsTaskCategory $c) => [
                'category' => $this->categoryOut($c),
                'tasks' => ($byCategory[$c->id] ?? collect())
                    ->map(fn (OpsTask $t) => $this->taskOut($t, $names))->values(),
            ])->values(),
            'total_tasks' => $tasks->count(),
            'open_count' => $tasks->where('status', OpsTask::STATUS_NOT_STARTED)->count(),
            // "In progress" deliberately counts WATCH too: a watched
            // item is live work, not an untouched one.
            'in_progress_count' => $tasks->whereIn('status', [OpsTask::STATUS_IN_PROGRESS, OpsTask::STATUS_WATCH])->count(),
            'blocked_count' => $tasks->where('status', OpsTask::STATUS_BLOCKED)->count(),
            'done_count' => $tasks->where('status', OpsTask::STATUS_DONE)->count(),
            'my_job_orders' => $this->jobOrderRollup($user->company_id, $staff->id),
            'my_software_tasks' => $this->softwareTaskRollup($user->company_id, $staff->id),
        ]);
    }

    public function createCategory(Request $request)
    {
        $user = $this->at($request, GroupModuleAuthority::EDIT);
        $data = $request->validate([
            'name' => 'required|string|min:1|max:255',
            'cadence_label' => 'sometimes|nullable|string|max:255',
            'owner_user_id' => 'sometimes|nullable|uuid',
        ]);
        $staff = $this->targetUser($user, $data['owner_user_id'] ?? null);

        $category = DB::transaction(function () use ($user, $staff, $data) {
            // New categories land at the end of that staff member's board.
            $sortOrder = OpsTaskCategory::where('owner_user_id', $staff->id)
                ->where('is_active', true)->count();

            $category = OpsTaskCategory::create([
                'company_id' => $user->company_id,
                'owner_user_id' => $staff->id,
                'name' => $data['name'],
                'cadence_label' => $data['cadence_label'] ?? null,
                'sort_order' => $sortOrder,
            ]);

            Audit::record(
                entityType: 'ops_task_category',
                entityId: $category->id,
                action: 'created',
                actorUserId: $user->id,
                details: "owner={$staff->full_name}, name={$data['name']}",
            );

            return $category;
        });

        return response()->json($this->categoryOut($category->refresh()));
    }

    public function createTask(Request $request)
    {
        $user = $this->at($request, GroupModuleAuthority::EDIT);
        $data = $request->validate([
            'category_id' => 'required|uuid',
            'title' => 'required|string|min:1|max:500',
            'status' => 'sometimes|string|in:'.implode(',', OpsTask::STATUSES),
            'next_action' => 'sometimes|nullable|string|max:500',
            'owner_label' => 'sometimes|nullable|string|max:255',
            'due_label' => 'sometimes|nullable|string|max:100',
            'follow_up_staff_id' => 'sometimes|nullable|uuid',
            'follow_up_date' => 'sometimes|nullable|date',
        ]);

        $category = $this->categoryOrFail($user->company_id, $data['category_id']);
        // Whoever owns the category owns the task -- so editing it goes
        // through the same own-or-manager check.
        $this->targetUser($user, $category->owner_user_id);

        $task = DB::transaction(function () use ($user, $category, $data) {
            $task = OpsTask::create([
                'company_id' => $user->company_id,
                'category_id' => $category->id,
                'owner_user_id' => $category->owner_user_id,
                'title' => $data['title'],
                'status' => $data['status'] ?? OpsTask::STATUS_NOT_STARTED,
                'next_action' => $data['next_action'] ?? null,
                'owner_label' => $data['owner_label'] ?? null,
                'due_label' => $data['due_label'] ?? null,
                'follow_up_staff_id' => $data['follow_up_staff_id'] ?? null,
                'follow_up_date' => $data['follow_up_date'] ?? null,
            ]);

            Audit::record(
                entityType: 'ops_task',
                entityId: $task->id,
                action: 'created',
                actorUserId: $user->id,
                details: "category={$category->name}, title={$data['title']}",
            );

            return $task;
        });

        return response()->json($this->taskOut($task->refresh(), $this->followUpName($task)));
    }

    public function updateTask(Request $request, string $taskId)
    {
        $user = $this->at($request, GroupModuleAuthority::EDIT);
        $data = $request->validate([
            'title' => 'sometimes|string|min:1|max:500',
            'status' => 'sometimes|string|in:'.implode(',', OpsTask::STATUSES),
            'next_action' => 'sometimes|nullable|string|max:500',
            'owner_label' => 'sometimes|nullable|string|max:255',
            'due_label' => 'sometimes|nullable|string|max:100',
            'follow_up_staff_id' => 'sometimes|nullable|uuid',
            'follow_up_date' => 'sometimes|nullable|date',
            // Explicit clear flags, because omitting a field means
            // "leave it alone" -- there is otherwise no way to say
            // "remove the follow-up" through a partial update.
            'clear_follow_up_staff' => 'sometimes|boolean',
            'clear_follow_up_date' => 'sometimes|boolean',
        ]);

        $task = $this->taskOrFail($user->company_id, $taskId);
        $this->targetUser($user, $task->owner_user_id);

        DB::transaction(function () use ($data, $task, $user) {
            $oldStatus = $task->status;

            foreach (['title', 'status', 'next_action', 'owner_label', 'due_label',
                'follow_up_staff_id', 'follow_up_date'] as $field) {
                if (array_key_exists($field, $data)) {
                    $task->$field = $data[$field];
                }
            }
            if ($data['clear_follow_up_staff'] ?? false) {
                $task->follow_up_staff_id = null;
            }
            if ($data['clear_follow_up_date'] ?? false) {
                $task->follow_up_date = null;
            }
            $task->save();

            // Only a real status CHANGE is audited -- Python does not
            // record an edit that leaves the status where it was.
            if (isset($data['status']) && $data['status'] !== $oldStatus) {
                Audit::record(
                    entityType: 'ops_task',
                    entityId: $task->id,
                    action: 'status_changed',
                    actorUserId: $user->id,
                    oldValue: ['status' => $oldStatus],
                    newValue: ['status' => $task->status],
                );
            }
        });

        $task->refresh();

        return response()->json($this->taskOut($task, $this->followUpName($task)));
    }

    public function archiveTask(Request $request, string $taskId)
    {
        $user = $this->at($request, GroupModuleAuthority::EDIT);
        $task = $this->taskOrFail($user->company_id, $taskId);
        $this->targetUser($user, $task->owner_user_id);

        DB::transaction(function () use ($task, $user) {
            // Archive, never delete.
            $task->is_active = false;
            $task->save();

            Audit::record(
                entityType: 'ops_task',
                entityId: $task->id,
                action: 'archived',
                actorUserId: $user->id,
                details: $task->title,
            );
        });

        $task->refresh();

        return response()->json($this->taskOut($task, $this->followUpName($task)));
    }

    // ── Rollup of real ERP work ─────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    private function jobOrderRollup(string $companyId, string $staffId): array
    {
        return JobOrder::where('company_id', $companyId)
            ->where('assigned_to_user_id', $staffId)
            ->whereIn('status', [JobOrder::STATUS_OPEN, JobOrder::STATUS_ASSIGNED])
            // Soonest due first, with undated ones last -- a job order
            // with no due date should not sort above one due today.
            ->orderByRaw('due_date ASC NULLS LAST')
            ->orderByDesc('created_at')->get()
            ->map(fn (JobOrder $jo) => [
                'id' => $jo->id,
                'job_order_number' => $jo->job_order_number,
                'subject' => $jo->subject,
                'status' => $jo->status,
                'due_date' => $jo->due_date?->toDateString(),
            ])->all();
    }

    /**
     * Untested Software Tasks this person is on, labelled by which hat
     * they are wearing. Someone who is BOTH programmer and tester on the
     * same task appears twice, once per role -- deliberate, and how
     * Python builds it: the board is telling them they owe two
     * different things on it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function softwareTaskRollup(string $companyId, string $staffId): array
    {
        $rows = [];
        foreach ([['Programmer', 'assigned_programmer_id'], ['Tester', 'tester_user_id']] as [$role, $column]) {
            foreach (SoftwareTask::where('company_id', $companyId)
                ->where($column, $staffId)
                ->where('is_tested', false)
                ->orderByDesc('created_at')->get() as $t) {
                $rows[] = [
                    'id' => $t->id,
                    'title' => $t->title,
                    'role' => $role,
                    'is_tested' => $t->is_tested,
                ];
            }
        }

        return $rows;
    }

    // ── Shared ──────────────────────────────────────────────────────

    private function at(Request $request, string $level): User
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, $level);

        return $user;
    }

    private function isManager(User $user): bool
    {
        return in_array($user->role, self::MANAGER_ROLES, true);
    }

    /**
     * Whose dashboard is being acted on. Anyone may act on their own;
     * only a manager may act on someone else's.
     */
    private function targetUser(User $user, ?string $staffId): User
    {
        if ($staffId === null || $staffId === $user->id) {
            return $user;
        }
        if (! $this->isManager($user)) {
            throw new ApiException(403, "Only the owner, service lead, or sales manager can view or edit another staff member's dashboard.");
        }
        $target = User::where('company_id', $user->company_id)->find($staffId);
        if (! $target) {
            throw new ApiException(404, 'Staff member not found');
        }

        return $target;
    }

    private function categoryOrFail(string $companyId, string $categoryId): OpsTaskCategory
    {
        $category = OpsTaskCategory::where('company_id', $companyId)->find($categoryId);
        if (! $category) {
            throw new ApiException(404, 'Category not found');
        }

        return $category;
    }

    private function taskOrFail(string $companyId, string $taskId): OpsTask
    {
        $task = OpsTask::where('company_id', $companyId)->find($taskId);
        if (! $task) {
            throw new ApiException(404, 'Task not found');
        }

        return $task;
    }

    /** @return Collection<string, string> */
    private function followUpName(OpsTask $task)
    {
        return $task->follow_up_staff_id === null
            ? collect()
            : User::whereKey($task->follow_up_staff_id)->pluck('full_name', 'id');
    }

    /** @return array<string, mixed> */
    private function categoryOut(OpsTaskCategory $c): array
    {
        return [
            'id' => $c->id,
            'owner_user_id' => $c->owner_user_id,
            'name' => $c->name,
            'cadence_label' => $c->cadence_label,
            'sort_order' => $c->sort_order,
        ];
    }

    /** @param Collection<string, string> $names */
    private function taskOut(OpsTask $t, $names): array
    {
        return [
            'id' => $t->id,
            'category_id' => $t->category_id,
            'owner_user_id' => $t->owner_user_id,
            'title' => $t->title,
            'status' => $t->status,
            'next_action' => $t->next_action,
            'owner_label' => $t->owner_label,
            'due_label' => $t->due_label,
            'follow_up_staff_id' => $t->follow_up_staff_id,
            'follow_up_staff_name' => $t->follow_up_staff_id === null ? null : ($names[$t->follow_up_staff_id] ?? null),
            'follow_up_date' => $t->follow_up_date?->toDateString(),
            'is_sample' => $t->is_sample,
        ];
    }
}
