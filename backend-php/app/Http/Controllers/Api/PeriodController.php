<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Exceptions\YearEndClosingError;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\AccountingPeriod;
use App\Models\FiscalYearClosure;
use App\Models\GstReturn;
use App\Models\GstReturnLine;
use App\Models\User;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\GstReturns;
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
            'gst' => ($gst = GstReturns::current($period)) ? $this->presentGstSummary($gst) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function presentGstSummary(GstReturn $gst): array
    {
        return [
            'id' => $gst->id,
            'version' => $gst->version,
            'calculated_at' => $gst->calculated_at->toIso8601String(),
            'calculated_by_name' => $gst->calculatedBy?->full_name,
            'output_tax_sgd' => (float) $gst->box_6_sgd,
            'input_tax_sgd' => (float) $gst->box_7_sgd,
            'net_gst_sgd' => (float) $gst->box_8_sgd,
            'submitted_at' => optional($gst->submitted_at)->toIso8601String(),
            'submitted_by_name' => $gst->submittedBy?->full_name,
            'revision_opened_at' => optional($gst->revision_opened_at)->toIso8601String(),
            'revision_opened_by_name' => $gst->revisionOpenedBy?->full_name,
            'revision_reason' => $gst->revision_reason,
            'revises_version' => $gst->revises?->version,
        ];
    }

    /** @return array<string, mixed> */
    public static function presentGstReturn(GstReturn $gst, bool $withLines = true): array
    {
        $boxes = [];
        foreach (GstReturn::BOXES as $n => $label) {
            $boxes[] = ['box' => $n, 'label' => $label, 'amount_sgd' => (float) $gst->{"box_{$n}_sgd"}];
        }

        return [
            'id' => $gst->id,
            'accounting_period_id' => $gst->accounting_period_id,
            'period_name' => $gst->period?->name,
            'period_start' => $gst->period_start->toDateString(),
            'period_end' => $gst->period_end->toDateString(),
            'version' => $gst->version,
            'status' => $gst->status,
            'calculated_at' => $gst->calculated_at->toIso8601String(),
            'calculated_by_name' => $gst->calculatedBy?->full_name,
            'submitted_at' => optional($gst->submitted_at)->toIso8601String(),
            'submitted_by_name' => $gst->submittedBy?->full_name,
            'revision_opened_at' => optional($gst->revision_opened_at)->toIso8601String(),
            'revision_opened_by_name' => $gst->revisionOpenedBy?->full_name,
            'revision_reason' => $gst->revision_reason,
            'revises_version' => $gst->revises?->version,
            'boxes' => $boxes,
            'output_document_count' => $gst->output_document_count,
            'input_document_count' => $gst->input_document_count,
            'lines' => $withLines ? $gst->lines()->orderBy('direction', 'desc')->orderBy('document_date')->orderBy('document_number')->get()
                ->map(fn (GstReturnLine $l) => [
                    'direction' => $l->direction,
                    'document_type' => $l->document_type,
                    'document_id' => $l->document_id,
                    'document_number' => $l->document_number,
                    'document_date' => $l->document_date->toDateString(),
                    'party_name' => $l->party_name,
                    'tax_code' => $l->tax_code,
                    'box' => $l->box,
                    'net_sgd' => (float) $l->net_sgd,
                    'gst_sgd' => (float) $l->gst_sgd,
                ])->values() : [],
        ];
    }

    /** The saved GST Calculation of a period (the current one, plus earlier versions' summaries). */
    public function gst(Request $request, string $periodId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');
        $period = $this->periodOrFail($user->company_id, $periodId);

        $current = GstReturns::current($period);

        return response()->json([
            'period' => $this->present($period),
            'current' => $current ? self::presentGstReturn($current) : null,
            'history' => GstReturn::where('accounting_period_id', $period->id)->orderByDesc('version')->get()
                ->map(fn (GstReturn $g) => self::presentGstReturn($g, withLines: false))->values(),
        ]);
    }

    /** Mark the current GST Calculation submitted to IRAS; the month is locked from then on. */
    public function submitGst(Request $request, string $periodId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');
        $period = $this->periodOrFail($user->company_id, $periodId);

        return response()->json(self::presentGstReturn(GstReturns::submit($period, $user)->load('period')));
    }

    /**
     * Revise a return already submitted to IRAS (Dennis, 2026-09-26):
     * needs a reason; the submitted return is kept, and the month can be
     * corrected, recalculated and submitted again.
     */
    public function reviseGst(Request $request, string $periodId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');
        $period = $this->periodOrFail($user->company_id, $periodId);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];

        return response()->json(self::presentGstReturn(GstReturns::openRevision($period, $user, $reason)->load('period')));
    }

    /**
     * GST Calculation (open item 4b.4): sum the locked period's documents
     * into the Form 5 boxes and keep them. Needs the period closed.
     */
    public function calculateGst(Request $request, string $periodId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');
        $period = $this->periodOrFail($user->company_id, $periodId);

        $gst = GstReturns::calculate($period, $user);

        return response()->json(self::presentGstReturn($gst->load('period')));
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
