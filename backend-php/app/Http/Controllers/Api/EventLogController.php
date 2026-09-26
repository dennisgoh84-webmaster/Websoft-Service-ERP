<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\AuditLogEntry;
use App\Models\GroupModuleAuthority;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\Exports;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Event Logs -- the readable face of the audit trail every module
 * writes to. Mirrors backend/app/routers/event_logs.py 1:1.
 *
 * Read-only by design: there is no endpoint here that creates, edits or
 * deletes an entry, which is what makes the trail worth having
 * (CLAUDE.md: "All important financial and operational transactions
 * must have audit trails").
 *
 * Gated on `event_logs` at VIEW.
 */
class EventLogController extends Controller
{
    private const MODULE = 'event_logs';

    /** @var array<int, string> */
    private const EXPORT_FIELDS = [
        'when', 'actor', 'action', 'entity_type', 'entity_id', 'old_value', 'new_value',
        'reason', 'details', 'ip_address', 'device_id', 'user_agent',
    ];

    /** Python caps a list page at 500 rows and an export at 5000. */
    private const MAX_LIMIT = 500;

    private const EXPORT_CAP = 5000;

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::VIEW);

        $limit = min(max((int) $request->query('limit', 100), 1), self::MAX_LIMIT);
        $offset = max((int) $request->query('offset', 0), 0);

        $rows = $this->filtered($user->company_id, $request)
            ->orderByDesc('at')->offset($offset)->limit($limit)->get();

        return response()->json($rows->map(fn (AuditLogEntry $r) => $this->out($r)));
    }

    public function exportCsv(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::VIEW);

        $rows = $this->exportRows($user->company_id, $request);
        $this->recordExportEvent($request, $user->id, count($rows), 'csv');

        return response(Exports::rowsToCsv(self::EXPORT_FIELDS, $rows), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename=event-logs.csv',
        ]);
    }

    public function exportExcel(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::VIEW);

        $rows = $this->exportRows($user->company_id, $request);
        $this->recordExportEvent($request, $user->id, count($rows), 'xlsx');

        $data = Exports::rowsToExcel(self::EXPORT_FIELDS, $rows, 'Event Logs');

        return response($data, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename=event-logs.xlsx',
        ]);
    }

    private function filtered(string $companyId, Request $request): Builder
    {
        // Multi-company: each company sees only its own trail. Entries
        // written before company stamping existed carry a null
        // company_id and are shown to everyone rather than hidden --
        // hiding them would silently shorten an audit trail.
        $query = AuditLogEntry::query()->where(function (Builder $q) use ($companyId) {
            $q->where('company_id', $companyId)->orWhereNull('company_id');
        });

        if ($entityType = $request->query('entity_type')) {
            $query->where('entity_type', $entityType);
        }
        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }
        if ($actor = $request->query('actor_user_id')) {
            $query->where('actor_user_id', $actor);
        }
        if ($from = $request->query('date_from')) {
            $query->where('at', '>=', Carbon::parse($from, config('app.timezone'))->startOfDay());
        }
        if ($to = $request->query('date_to')) {
            // Python compares against the START of the following day, so
            // date_to is inclusive of everything logged on that date.
            $query->where('at', '<', Carbon::parse($to, config('app.timezone'))->addDay()->startOfDay());
        }
        if ($q = $request->query('q')) {
            $like = '%'.$q.'%';
            $query->where(function (Builder $sub) use ($like) {
                $sub->where('details', 'ilike', $like)
                    ->orWhere('reason', 'ilike', $like)
                    ->orWhere('actor_name', 'ilike', $like)
                    ->orWhere('entity_type', 'ilike', $like)
                    ->orWhere('action', 'ilike', $like);
            });
        }

        return $query;
    }

    /** @return array<int, array<string, mixed>> */
    private function exportRows(string $companyId, Request $request): array
    {
        return $this->filtered($companyId, $request)
            ->orderByDesc('at')->limit(self::EXPORT_CAP)->get()
            ->map(fn (AuditLogEntry $r) => [
                'when' => $r->at?->toIso8601String() ?? '',
                // Python exports a missing value as an empty string
                // while the JSON keeps it null -- both preserved.
                'actor' => $r->actor_name ?? '',
                'action' => $r->action,
                'entity_type' => $r->entity_type,
                'entity_id' => (string) $r->entity_id,
                'old_value' => $r->old_value ?? '',
                'new_value' => $r->new_value ?? '',
                'reason' => $r->reason ?? '',
                'details' => $r->details ?? '',
                'ip_address' => $r->ip_address ?? '',
                'device_id' => $r->device_id ?? '',
                'user_agent' => $r->user_agent ?? '',
            ])->all();
    }

    /**
     * Exporting the audit trail is itself a reportable event: who ran
     * it, with which filters, and how many rows came back. Without
     * this, bulk-reading the trail would be the one action it does not
     * record.
     */
    private function recordExportEvent(Request $request, string $actorUserId, int $rowCount, string $format): void
    {
        $parts = [];
        foreach (['entity_type', 'action', 'actor_user_id', 'date_from', 'date_to', 'q'] as $key) {
            if ($value = $request->query($key)) {
                $parts[] = "{$key}={$value}";
            }
        }
        $filters = $parts ? implode(', ', $parts) : 'none';

        Audit::recordReportGenerated(
            $actorUserId,
            'event_log_export',
            'Event Log '.strtoupper($format)." export -- {$rowCount} rows, filters: {$filters}",
        );
    }

    /** @return array<string, mixed> */
    private function out(AuditLogEntry $r): array
    {
        return [
            'id' => $r->id,
            'at' => $r->at?->toJSON(),
            'entity_type' => $r->entity_type,
            'entity_id' => $r->entity_id,
            'action' => $r->action,
            'actor_user_id' => $r->actor_user_id,
            'actor_name' => $r->actor_name,
            'reason' => $r->reason,
            'details' => $r->details,
            'old_value' => $r->old_value,
            'new_value' => $r->new_value,
            'ip_address' => $r->ip_address,
            'device_id' => $r->device_id,
            'user_agent' => $r->user_agent,
        ];
    }
}
