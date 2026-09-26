<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\GroupModuleAuthority;
use App\Models\SoftwareTask;
use App\Models\User;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\Exports;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Software Tasks. Mirrors backend/app/routers/software_tasks.py 1:1.
 *
 * Gated on `software_development`. Confirmed 2026-09-10 as a minimal
 * first slice (is_tested / mark-tested / reopen-testing); statuses added
 * 2026-09-26 (decision 12.1) -- see SoftwareTask::TRANSITIONS and
 * changeStatus(), plus the per-programmer cards in programmers().
 *
 * Converting this module closes three things recorded elsewhere as
 * gaps: Support Monitoring's "Un-Test S/T" placeholder zero, the
 * `incidents.converted_software_task_id` foreign key deferred "until
 * software_tasks exists", and the Incidents module's
 * convert-to-software-task route.
 */
class SoftwareTaskController extends Controller
{
    private const MODULE = 'software_development';

    /** @var array<int, string> */
    private const EXPORT_FIELDS = [
        'title', 'modules_affected', 'assigned_programmer', 'programming_finish_date',
        'programming_hours', 'tester', 'is_tested', 'status',
    ];

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::VIEW);

        return response()->json(
            $this->filtered($user->company_id, $request)->map(fn (SoftwareTask $t) => $this->out($t))
        );
    }

    public function exportCsv(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::VIEW);

        return response(Exports::rowsToCsv(self::EXPORT_FIELDS, $this->exportRows($user->company_id, $request)), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename=software-tasks.csv',
        ]);
    }

    public function exportExcel(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::VIEW);

        $data = Exports::rowsToExcel(
            self::EXPORT_FIELDS,
            $this->exportRows($user->company_id, $request),
            'Software Tasks'
        );

        return response($data, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename=software-tasks.xlsx',
        ]);
    }

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::EDIT);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'sometimes|nullable|string',
            'modules_affected' => 'sometimes|nullable|string|max:500',
            'assigned_programmer_id' => 'sometimes|nullable|uuid',
            'programming_finish_date' => 'sometimes|nullable|date',
            'programming_hours' => 'sometimes|nullable|numeric|min:0',
            'tester_user_id' => 'sometimes|nullable|uuid',
        ]);

        $this->assertUsersInCompany($user->company_id, $data);

        $task = DB::transaction(function () use ($data, $user) {
            $task = SoftwareTask::create($data + [
                'company_id' => $user->company_id,
                'created_by_user_id' => $user->id,
            ]);

            Audit::record(
                entityType: 'software_task',
                entityId: $task->id,
                action: 'created',
                actorUserId: $user->id,
                details: "title={$data['title']}",
            );

            return $task;
        });

        return response()->json($this->out($task->refresh()));
    }

    public function update(Request $request, string $taskId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::EDIT);

        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'modules_affected' => 'sometimes|nullable|string|max:500',
            'assigned_programmer_id' => 'sometimes|nullable|uuid',
            'programming_finish_date' => 'sometimes|nullable|date',
            'programming_hours' => 'sometimes|nullable|numeric|min:0',
            'tester_user_id' => 'sometimes|nullable|uuid',
        ]);

        $task = $this->taskOrFail($user->company_id, $taskId);
        $this->assertUsersInCompany($user->company_id, $data);

        DB::transaction(function () use ($data, $task, $user) {
            $oldValue = [];
            $newValue = [];
            foreach ([
                'title', 'description', 'modules_affected', 'assigned_programmer_id',
                'programming_finish_date', 'programming_hours', 'tester_user_id',
            ] as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }
                $old = $task->$field;
                $new = $data[$field];
                if ($old == $new) {
                    continue;
                }
                // Python stringifies both sides but keeps null as null.
                $oldValue[$field] = $old === null ? null : (string) $old;
                $newValue[$field] = $new === null ? null : (string) $new;
                $task->$field = $new;
            }
            $task->save();

            Audit::record(
                entityType: 'software_task',
                entityId: $task->id,
                action: 'updated',
                actorUserId: $user->id,
                oldValue: $oldValue ?: null,
                newValue: $newValue ?: null,
            );
        });

        return response()->json($this->out($task->refresh()));
    }

    /** Mark tested -- from Open, Programming or For Testing (the button that predates statuses). */
    public function markTested(Request $request, string $taskId)
    {
        return $this->moveTo($request, $taskId, SoftwareTask::STATUS_TESTED,
            [SoftwareTask::STATUS_OPEN, SoftwareTask::STATUS_PROGRAMMING, SoftwareTask::STATUS_FOR_TESTING], 'marked_tested');
    }

    /** Reopen testing -- Tested back to For Testing. */
    public function reopenTesting(Request $request, string $taskId)
    {
        return $this->moveTo($request, $taskId, SoftwareTask::STATUS_FOR_TESTING, [SoftwareTask::STATUS_TESTED], 'testing_reopened');
    }

    /**
     * Move a task to another status along SoftwareTask::TRANSITIONS
     * (decision 12.1). Released is final and needs EDIT, like every move.
     */
    public function changeStatus(Request $request, string $taskId)
    {
        $status = $request->validate(['status' => ['required', 'string', 'in:'.implode(',', SoftwareTask::STATUSES)]])['status'];

        return $this->moveTo($request, $taskId, $status, array_keys(array_filter(
            SoftwareTask::TRANSITIONS, fn ($to) => in_array($status, $to, true),
        )), 'status_changed');
    }

    /** @param list<string> $allowedFrom */
    private function moveTo(Request $request, string $taskId, string $status, array $allowedFrom, string $action)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::EDIT);
        $task = $this->taskOrFail($user->company_id, $taskId);

        $from = $task->status;
        if (! in_array($from, $allowedFrom, true)) {
            throw new ApiException(409, sprintf('A task that is %s cannot move to %s.', self::STATUS_LABELS[$from] ?? $from, self::STATUS_LABELS[$status] ?? $status));
        }

        DB::transaction(function () use ($task, $user, $status, $from, $action) {
            $now = Carbon::now();
            $task->status = $status;
            $task->status_changed_at = $now;
            $tested = in_array($status, [SoftwareTask::STATUS_TESTED, SoftwareTask::STATUS_RELEASED], true);
            if ($tested && ! $task->is_tested) {
                $task->tested_at = $now;
            }
            if (! $tested) {
                $task->tested_at = null;
            }
            $task->is_tested = $tested;
            if ($status === SoftwareTask::STATUS_RELEASED) {
                $task->released_at = $now;
                $task->released_by_user_id = $user->id;
            }
            $task->save();

            Audit::record(
                entityType: 'software_task',
                entityId: $task->id,
                action: $action,
                actorUserId: $user->id,
                companyId: $task->company_id,
                details: sprintf('"%s": %s -> %s', $task->title, self::STATUS_LABELS[$from] ?? $from, self::STATUS_LABELS[$status]),
                oldValue: ['status' => $from],
                newValue: ['status' => $status],
            );
        });

        return response()->json($this->out($task->refresh()));
    }

    private const STATUS_LABELS = [
        SoftwareTask::STATUS_OPEN => 'Open',
        SoftwareTask::STATUS_PROGRAMMING => 'Programming',
        SoftwareTask::STATUS_FOR_TESTING => 'For Testing',
        SoftwareTask::STATUS_TESTED => 'Tested',
        SoftwareTask::STATUS_RELEASED => 'Released',
    ];

    /**
     * Per-programmer cards (decision 12.1 / 12.3): each programmer's
     * open tasks (Open or Programming), the overdue ones among every
     * task not yet Tested (finish date passed), and those awaiting test.
     */
    public function programmers(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::VIEW);

        $today = Carbon::today();
        $tasks = SoftwareTask::where('company_id', $user->company_id)
            ->whereNotIn('status', [SoftwareTask::STATUS_TESTED, SoftwareTask::STATUS_RELEASED])->get();
        $names = User::whereIn('id', $tasks->pluck('assigned_programmer_id')->filter()->unique())->pluck('full_name', 'id');

        $cards = $tasks->groupBy(fn (SoftwareTask $t) => $t->assigned_programmer_id ?? 'none')->map(fn ($group, $id) => [
            'programmer_id' => $id === 'none' ? null : $id,
            'name' => $id === 'none' ? 'No programmer' : ($names[$id] ?? 'Unknown'),
            'open' => $group->whereIn('status', [SoftwareTask::STATUS_OPEN, SoftwareTask::STATUS_PROGRAMMING])->count(),
            'awaiting_test' => $group->where('status', SoftwareTask::STATUS_FOR_TESTING)->count(),
            'overdue' => $group->filter(fn (SoftwareTask $t) => $t->programming_finish_date !== null && $t->programming_finish_date->lt($today))->count(),
            'hours' => (float) $group->sum(fn (SoftwareTask $t) => (float) ($t->programming_hours ?? 0)),
        ])->sortBy(fn ($c) => [$c['programmer_id'] === null, $c['name']])->values();

        return response()->json($cards);
    }

    private function taskOrFail(string $companyId, string $taskId): SoftwareTask
    {
        $task = SoftwareTask::where('company_id', $companyId)->find($taskId);
        if (! $task) {
            throw new ApiException(404, 'Software task not found');
        }

        return $task;
    }

    /**
     * A programmer or tester must be a user of the caller's own company.
     * Python passes these ids straight through; refusing a foreign one
     * is hardening, recorded in docs/php-conversion-plan.md -- the same
     * treatment the Stock conversion gave its cross-company ids.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertUsersInCompany(string $companyId, array $data): void
    {
        foreach (['assigned_programmer_id', 'tester_user_id'] as $field) {
            if (empty($data[$field])) {
                continue;
            }
            $exists = User::where('company_id', $companyId)->whereKey($data[$field])->exists();
            if (! $exists) {
                throw new ApiException(404, 'User not found');
            }
        }
    }

    /** @return Collection<int, SoftwareTask> */
    private function filtered(string $companyId, Request $request)
    {
        $query = SoftwareTask::where('company_id', $companyId);
        if ($programmer = $request->query('assigned_programmer_id')) {
            $query->where('assigned_programmer_id', $programmer);
        }
        if ($tester = $request->query('tester_user_id')) {
            $query->where('tester_user_id', $tester);
        }
        if ($request->boolean('untested_only')) {
            $query->where('is_tested', false);
        }
        if (($status = $request->query('status')) && in_array($status, SoftwareTask::STATUSES, true)) {
            $query->where('status', $status);
        }

        return $query->orderByDesc('created_at')->get();
    }

    /** @return array<int, array<string, mixed>> */
    private function exportRows(string $companyId, Request $request): array
    {
        $tasks = $this->filtered($companyId, $request);
        $names = User::whereIn('id', $tasks->pluck('assigned_programmer_id')
            ->merge($tasks->pluck('tester_user_id'))->filter()->unique())
            ->pluck('full_name', 'id');

        return $tasks->map(fn (SoftwareTask $t) => [
            'title' => $t->title,
            // Python exports a missing value as an empty string while
            // the JSON keeps it null -- both preserved.
            'modules_affected' => $t->modules_affected ?? '',
            'assigned_programmer' => $names[$t->assigned_programmer_id] ?? '',
            'programming_finish_date' => $t->programming_finish_date?->toDateString() ?? '',
            'programming_hours' => $t->programming_hours === null ? '' : (string) $t->programming_hours,
            'tester' => $names[$t->tester_user_id] ?? '',
            'is_tested' => $t->is_tested,
            'status' => self::STATUS_LABELS[$t->status] ?? $t->status,
        ])->all();
    }

    /** @return array<string, mixed> */
    private function out(SoftwareTask $t): array
    {
        return [
            'id' => $t->id,
            'title' => $t->title,
            'description' => $t->description,
            'modules_affected' => $t->modules_affected,
            'assigned_programmer_id' => $t->assigned_programmer_id,
            'programming_finish_date' => $t->programming_finish_date?->toDateString(),
            // Python's SoftwareTaskOut types this as float.
            'programming_hours' => $t->programming_hours === null ? null : (float) $t->programming_hours,
            'tester_user_id' => $t->tester_user_id,
            'is_tested' => $t->is_tested,
            'status' => $t->status,
            'next_statuses' => SoftwareTask::TRANSITIONS[$t->status] ?? [],
            'released_at' => $t->released_at?->toJSON(),
            'tested_at' => $t->tested_at?->toJSON(),
            'created_at' => $t->created_at?->toJSON(),
        ];
    }
}
