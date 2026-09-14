<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Exceptions\YearEndClosingError;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\AccountingPeriod;
use App\Models\FiscalYearClosure;
use App\Models\User;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\Periods;
use Illuminate\Http\Request;

/**
 * Accounting Periods and Year-End Closing. Mirrors
 * backend/app/routers/periods.py -- see App\Services\Periods for the
 * lock matrix, VALID_DOC_OPERATIONS, and the Year-End Closing
 * mechanics.
 *
 * NOT yet converted: CSV/Excel export (no route exposes it in the
 * Python router either -- Accounting Periods has never had an export
 * button on the frontend).
 */
class PeriodController extends Controller
{
    private const MODULE = 'finance_accounting';

    private function periodOrFail(string $companyId, string $periodId): AccountingPeriod
    {
        $period = AccountingPeriod::with('locks')->find($periodId);
        if (! $period || $period->company_id !== $companyId) {
            throw new ApiException(404, 'Period not found');
        }

        return $period;
    }

    private function presentLock($lock): array
    {
        return [
            'id' => $lock->id,
            'doc_type' => $lock->doc_type,
            'operation' => $lock->operation,
            'is_locked' => $lock->is_locked,
            'locked_by_user_id' => $lock->locked_by_user_id,
            'locked_at' => optional($lock->locked_at)->toIso8601String(),
        ];
    }

    private function present(AccountingPeriod $period): array
    {
        return [
            'id' => $period->id,
            'fiscal_year' => $period->fiscal_year,
            'name' => $period->name,
            'period_start' => $period->period_start->toDateString(),
            'period_end' => $period->period_end->toDateString(),
            'status' => $period->status,
            'closed_at' => optional($period->closed_at)->toIso8601String(),
            'locks' => $period->locks->map(fn ($lk) => $this->presentLock($lk))->values(),
        ];
    }

    private function presentClosure(FiscalYearClosure $closure): array
    {
        return [
            'id' => $closure->id,
            'fiscal_year' => $closure->fiscal_year,
            'retained_earnings_account_id' => $closure->retained_earnings_account_id,
            'closing_journal_entry_id' => $closure->closing_journal_entry_id,
            'closed_at' => $closure->closed_at->toIso8601String(),
        ];
    }

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $query = AccountingPeriod::with('locks')->where('company_id', $user->company_id);
        if ($request->filled('fiscal_year')) {
            $query->where('fiscal_year', $request->query('fiscal_year'));
        }

        return $query->orderBy('period_start')->get()->map(fn (AccountingPeriod $p) => $this->present($p))->values();
    }

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $data = $request->validate([
            'fiscal_year' => 'required|integer',
            'name' => 'required|string|min:1|max:50',
            'period_start' => 'required|date',
            'period_end' => 'required|date',
        ]);

        if ($data['period_end'] < $data['period_start']) {
            throw new ApiException(422, 'period_end cannot be before period_start.');
        }

        $overlap = AccountingPeriod::where('company_id', $user->company_id)
            ->where('period_start', '<=', $data['period_end'])
            ->where('period_end', '>=', $data['period_start'])
            ->first();
        if ($overlap) {
            throw new ApiException(409, "Overlaps existing period \"{$overlap->name}\" ({$overlap->period_start->toDateString()} to {$overlap->period_end->toDateString()}).");
        }

        $period = AccountingPeriod::create([
            'company_id' => $user->company_id,
            'fiscal_year' => $data['fiscal_year'],
            'name' => $data['name'],
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
        ]);
        Periods::seedLocksForPeriod($period, false);

        Audit::record(
            entityType: 'accounting_period',
            entityId: $period->id,
            action: 'created',
            actorUserId: $user->id,
            details: "{$period->name} ({$data['period_start']} to {$data['period_end']})",
            newValue: ['name' => $period->name, 'period_start' => $data['period_start'], 'period_end' => $data['period_end']],
        );

        return response()->json($this->present($period->fresh('locks')));
    }

    /** Lock or unlock one doc-type x operation cell. */
    public function toggleLock(Request $request, string $periodId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $period = $this->periodOrFail($user->company_id, $periodId);
        $data = $request->validate([
            'doc_type' => 'required|in:'.implode(',', array_keys(Periods::VALID_DOC_OPERATIONS)),
            'operation' => 'required|in:update,reverse,bank,unbank,gl,ungl',
            'locked' => 'required|boolean',
        ]);

        try {
            Periods::toggleLock($period, $data['doc_type'], $data['operation'], $data['locked'], $user->id);
        } catch (\InvalidArgumentException $e) {
            throw new ApiException(422, $e->getMessage());
        }

        $action = $data['locked'] ? 'locked' : 'unlocked';
        Audit::record(
            entityType: 'accounting_period',
            entityId: $period->id,
            action: "lock_{$action}",
            actorUserId: $user->id,
            details: "{$data['doc_type']}/{$data['operation']} {$action} in {$period->name}",
            newValue: ['doc_type' => $data['doc_type'], 'operation' => $data['operation'], 'is_locked' => $data['locked']],
        );

        return response()->json($this->present($period->fresh('locks')));
    }

    /** Lock every operation for every doc type ("Close All"). */
    public function close(Request $request, string $periodId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $period = $this->periodOrFail($user->company_id, $periodId);
        if ($period->status === AccountingPeriod::STATUS_CLOSED) {
            throw new ApiException(422, 'That period is already fully closed.');
        }

        Periods::closePeriod($period, $user->id);
        Audit::record(
            entityType: 'accounting_period',
            entityId: $period->id,
            action: 'closed',
            actorUserId: $user->id,
            details: $period->name,
            oldValue: ['status' => 'open'],
            newValue: ['status' => 'closed'],
        );

        return response()->json($this->present($period->fresh('locks')));
    }

    /** Unlock every operation for every doc type ("Open All"). Owner-only. */
    public function reopen(Request $request, string $periodId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');
        if ($user->role !== User::ROLE_OWNER) {
            throw new ApiException(403, 'Only the owner can reopen a closed accounting period.');
        }

        $period = $this->periodOrFail($user->company_id, $periodId);
        if ($period->status === AccountingPeriod::STATUS_OPEN) {
            $anyLocked = $period->locks->contains(fn ($lk) => $lk->is_locked);
            if (! $anyLocked) {
                throw new ApiException(422, 'That period is already fully open.');
            }
        }

        Periods::reopenPeriod($period);
        Audit::record(
            entityType: 'accounting_period',
            entityId: $period->id,
            action: 'reopened',
            actorUserId: $user->id,
            details: $period->name,
            oldValue: ['status' => 'closed'],
            newValue: ['status' => 'open'],
        );

        return response()->json($this->present($period->fresh('locks')));
    }

    public function listClosures(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return FiscalYearClosure::where('company_id', $user->company_id)
            ->orderByDesc('fiscal_year')
            ->get()
            ->map(fn (FiscalYearClosure $c) => $this->presentClosure($c))
            ->values();
    }

    /**
     * Year-End Closing: posts one closing journal entry moving every
     * Revenue/Expense account's movement for the fiscal year into the
     * chosen Equity account. Owner-only.
     */
    public function closeFiscalYear(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');
        if ($user->role !== User::ROLE_OWNER) {
            throw new ApiException(403, 'Only the owner can perform Year-End Closing.');
        }

        $data = $request->validate([
            'fiscal_year' => 'required|integer',
            'retained_earnings_account_id' => 'required|uuid',
        ]);

        try {
            $entry = Periods::closeFiscalYear($user->company_id, $data['fiscal_year'], $data['retained_earnings_account_id'], $user->id);
        } catch (YearEndClosingError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        $closure = FiscalYearClosure::where('company_id', $user->company_id)
            ->where('fiscal_year', $data['fiscal_year'])
            ->firstOrFail();

        Audit::record(
            entityType: 'fiscal_year_closure',
            entityId: $closure->id,
            action: 'closed',
            actorUserId: $user->id,
            details: "FY{$data['fiscal_year']} closed via voucher {$entry->voucher_number}",
            newValue: ['fiscal_year' => $data['fiscal_year'], 'voucher_number' => $entry->voucher_number],
        );

        return response()->json($this->presentClosure($closure));
    }
}
