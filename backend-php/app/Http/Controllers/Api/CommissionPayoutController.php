<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\CommissionPayout;
use App\Models\GroupModuleAuthority;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\CommissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Commission Payouts. Mirrors backend/app/routers/commissions.py
 * (docs/open-business-decisions.md 6.3 approval, 6.4 clawback, 6.5
 * payout).
 *
 * Gated on `accounting_reports`, the same key Python uses and the one
 * frontend/src/components/Layout.tsx already tests for this screen --
 * not a key of its own.
 *
 * The authority split is Python's: reading needs VIEW, submitting for
 * approval needs EDIT, and generating, approving, rejecting, paying or
 * cancelling needs FULL -- so the person who prepares a batch cannot
 * also be the one who approves and pays it unless they hold FULL.
 *
 * Every transition is audited with the status it moved from and to.
 */
class CommissionPayoutController extends Controller
{
    private const MODULE = 'accounting_reports';

    /** @return array<string, mixed> */
    private function present(CommissionPayout $p): array
    {
        return [
            'id' => $p->id,
            'company_id' => $p->company_id,
            'payout_number' => $p->payout_number,
            'payout_type' => $p->payout_type,
            'status' => $p->status,
            'sales_staff_id' => $p->sales_staff_id,
            'period_month' => $p->period_month,
            'period_start' => optional($p->period_start)->toDateString(),
            'period_end' => optional($p->period_end)->toDateString(),
            'amount_sgd' => (float) $p->amount_sgd,
            'rate_percent' => (float) $p->rate_percent,
            'clawback_invoice_id' => $p->clawback_invoice_id,
            'clawback_reason' => $p->clawback_reason,
            'submitted_by_user_id' => $p->submitted_by_user_id,
            'submitted_at' => optional($p->submitted_at)->toIso8601String(),
            'approved_by_user_id' => $p->approved_by_user_id,
            'approved_at' => optional($p->approved_at)->toIso8601String(),
            'paid_date' => optional($p->paid_date)->toDateString(),
            'paid_reference' => $p->paid_reference,
            'paid_by_user_id' => $p->paid_by_user_id,
            'notes' => $p->notes,
            'created_at' => optional($p->created_at)->toIso8601String(),
            'updated_at' => optional($p->updated_at)->toIso8601String(),
        ];
    }

    /** Every payout for one month, newest first -- what the batch actions return. */
    private function monthResponse(string $companyId, string $periodMonth)
    {
        return response()->json(
            CommissionPayout::where('company_id', $companyId)
                ->where('period_month', $periodMonth)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (CommissionPayout $p) => $this->present($p))
                ->all()
        );
    }

    public function generate(Request $request)
    {
        $user = $this->actor($request, GroupModuleAuthority::FULL);
        $data = $this->periodMonth($request);

        $payouts = DB::transaction(fn () => CommissionService::generatePayouts(
            $user->company_id, $data['period_month'], $user->id,
        ));

        foreach ($payouts as $payout) {
            Audit::record(
                entityType: 'commission_payout',
                entityId: $payout->id,
                action: 'generated',
                actorUserId: $user->id,
                newValue: [
                    'payout_number' => $payout->payout_number,
                    'period_month' => $payout->period_month,
                    'amount_sgd' => (float) $payout->amount_sgd,
                    'sales_staff_id' => $payout->sales_staff_id,
                ],
            );
        }

        return $this->monthResponse($user->company_id, $data['period_month']);
    }

    public function index(Request $request)
    {
        $user = $this->actor($request, GroupModuleAuthority::VIEW);

        $query = CommissionPayout::where('company_id', $user->company_id);
        if ($request->filled('period_month')) {
            $query->where('period_month', $request->query('period_month'));
        }
        if ($request->filled('status')) {
            $status = $request->query('status');
            if (! in_array($status, CommissionPayout::STATUSES, true)) {
                return response()->json(['detail' => "Invalid status: {$status}"], 422);
            }
            $query->where('status', $status);
        }
        if ($request->filled('sales_staff_id')) {
            $query->where('sales_staff_id', $request->query('sales_staff_id'));
        }

        return response()->json(
            $query->orderByDesc('period_month')->orderByDesc('created_at')->get()
                ->map(fn (CommissionPayout $p) => $this->present($p))->all()
        );
    }

    public function show(Request $request, string $payoutId)
    {
        $user = $this->actor($request, GroupModuleAuthority::VIEW);

        return response()->json(
            $this->present(CommissionService::payoutOrFail($payoutId, $user->company_id))
        );
    }

    public function submit(Request $request, string $payoutId)
    {
        $user = $this->actor($request, GroupModuleAuthority::EDIT);
        $payout = CommissionService::payoutOrFail($payoutId, $user->company_id);
        $payout = DB::transaction(fn () => CommissionService::submit($payout, $user->id));
        $this->auditTransition($payout, $user->id, 'submitted', 'draft', 'pending_approval');

        return response()->json($this->present($payout));
    }

    public function approve(Request $request, string $payoutId)
    {
        $user = $this->actor($request, GroupModuleAuthority::FULL);
        $payout = CommissionService::payoutOrFail($payoutId, $user->company_id);
        $payout = DB::transaction(fn () => CommissionService::approve($payout, $user->id));
        $this->auditTransition($payout, $user->id, 'approved', 'pending_approval', 'approved');

        return response()->json($this->present($payout));
    }

    public function reject(Request $request, string $payoutId)
    {
        $user = $this->actor($request, GroupModuleAuthority::FULL);
        $data = $request->validate(['reason' => 'sometimes|nullable|string']);
        $payout = CommissionService::payoutOrFail($payoutId, $user->company_id);
        $payout = DB::transaction(fn () => CommissionService::reject($payout, $data['reason'] ?? null));
        $this->auditTransition($payout, $user->id, 'rejected', 'pending_approval', 'draft');

        return response()->json($this->present($payout));
    }

    public function pay(Request $request, string $payoutId)
    {
        $user = $this->actor($request, GroupModuleAuthority::FULL);
        $data = $request->validate([
            'paid_date' => 'required|date',
            'paid_reference' => 'sometimes|nullable|string|max:200',
        ]);
        $payout = CommissionService::payoutOrFail($payoutId, $user->company_id);
        $payout = DB::transaction(fn () => CommissionService::markPaid(
            $payout, $user->id, $data['paid_date'], $data['paid_reference'] ?? null,
        ));

        Audit::record(
            entityType: 'commission_payout',
            entityId: $payout->id,
            action: 'paid',
            actorUserId: $user->id,
            oldValue: ['status' => 'approved'],
            newValue: [
                'status' => 'paid',
                'paid_date' => $data['paid_date'],
                'paid_reference' => $data['paid_reference'] ?? null,
            ],
        );

        return response()->json($this->present($payout));
    }

    public function cancel(Request $request, string $payoutId)
    {
        $user = $this->actor($request, GroupModuleAuthority::FULL);
        $payout = CommissionService::payoutOrFail($payoutId, $user->company_id);
        $payout = DB::transaction(fn () => CommissionService::cancel($payout));

        Audit::record(
            entityType: 'commission_payout',
            entityId: $payout->id,
            action: 'cancelled',
            actorUserId: $user->id,
            newValue: ['status' => 'cancelled'],
        );

        return response()->json($this->present($payout));
    }

    public function submitAll(Request $request)
    {
        $user = $this->actor($request, GroupModuleAuthority::EDIT);
        $data = $this->periodMonth($request);

        $payouts = CommissionPayout::where('company_id', $user->company_id)
            ->where('period_month', $data['period_month'])
            ->where('status', CommissionPayout::STATUS_DRAFT)
            ->get();
        DB::transaction(function () use ($payouts, $user) {
            foreach ($payouts as $payout) {
                CommissionService::submit($payout, $user->id);
                $this->auditTransition($payout, $user->id, 'submitted', 'draft', 'pending_approval');
            }
        });

        return $this->monthResponse($user->company_id, $data['period_month']);
    }

    public function approveAll(Request $request)
    {
        $user = $this->actor($request, GroupModuleAuthority::FULL);
        $data = $this->periodMonth($request);

        $payouts = CommissionPayout::where('company_id', $user->company_id)
            ->where('period_month', $data['period_month'])
            ->where('status', CommissionPayout::STATUS_PENDING_APPROVAL)
            ->get();
        DB::transaction(function () use ($payouts, $user) {
            foreach ($payouts as $payout) {
                CommissionService::approve($payout, $user->id);
                $this->auditTransition($payout, $user->id, 'approved', 'pending_approval', 'approved');
            }
        });

        return $this->monthResponse($user->company_id, $data['period_month']);
    }

    private function actor(Request $request, string $level)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, $level);

        return $user;
    }

    /** @return array{period_month: string} */
    private function periodMonth(Request $request): array
    {
        return $request->validate([
            'period_month' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);
    }

    private function auditTransition(CommissionPayout $payout, string $actorId, string $action, string $from, string $to): void
    {
        Audit::record(
            entityType: 'commission_payout',
            entityId: $payout->id,
            action: $action,
            actorUserId: $actorId,
            oldValue: ['status' => $from],
            newValue: ['status' => $to],
        );
    }
}
