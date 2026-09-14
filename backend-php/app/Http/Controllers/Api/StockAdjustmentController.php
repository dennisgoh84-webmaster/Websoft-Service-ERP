<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Exceptions\InventoryRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\User;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\InventoryService;
use App\Services\StockDocumentGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Stock Adjustment -- INV-001. Mirrors the `/api/stock/adjustments`
 * half of backend/app/routers/stock.py.
 *
 * INV-001 (CONFIRMED 2026-09-09): adjustments for discrepancies,
 * damage, loss or a count variance **require manager approval before
 * taking effect** -- warehouse/hardware staff cannot adjust stock
 * unilaterally. Enforced by the status machine (draft -> submit ->
 * pending_approval -> approve | reject) plus the module gate: only an
 * approve applies stock changes, and only a FULL `stock_adjustment`
 * authority can call it. Everything else leaves stock untouched.
 *
 * RBAC: `stock_adjustment` -- VIEW to list, FULL to create, submit,
 * approve or reject -- identical to the Python router. Who counts as
 * "a manager" is Group Authority's decision, exactly as in `backend/`:
 * neither backend adds a named-role check, and neither stops a creator
 * approving their own adjustment. That separation of duties was never
 * confirmed, so it is not invented here.
 */
class StockAdjustmentController extends Controller
{
    private const MODULE = 'stock_adjustment';

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return StockAdjustment::with('lines')
            ->where('company_id', $user->company_id)
            ->orderByDesc('created_at')->get()
            ->map(fn (StockAdjustment $a) => $this->present($a))->values();
    }

    public function store(Request $request)
    {
        $user = $this->writer($request);

        $data = $request->validate([
            'warehouse_id' => 'required|uuid',
            'adjustment_date' => 'sometimes|nullable|date',
            'reason' => 'sometimes|nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.stock_item_id' => 'required|uuid',
            // Signed and unconstrained, exactly like
            // AdjustmentLineCreate's `quantity_change: int` -- +ve
            // increases, -ve decreases, and 0 is accepted (it simply
            // moves nothing on approval).
            'lines.*.quantity_change' => 'required|integer',
            'lines.*.notes' => 'sometimes|nullable|string',
        ]);

        StockDocumentGuard::assertWarehouse($user, $data['warehouse_id']);
        StockDocumentGuard::assertStockItems($user, $data['lines']);

        $adjustment = DB::transaction(function () use ($user, $data) {
            $adjustment = StockAdjustment::create([
                'company_id' => $user->company_id,
                'adj_number' => $this->nextAdjustmentNumber($user->company_id),
                'warehouse_id' => $data['warehouse_id'],
                'adjustment_date' => $data['adjustment_date'] ?? now(),
                'reason' => $data['reason'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($data['lines'] as $line) {
                StockAdjustmentLine::create([
                    'adjustment_id' => $adjustment->id,
                    'stock_item_id' => $line['stock_item_id'],
                    'quantity_change' => $line['quantity_change'],
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            Audit::record('stock_adjustment', $adjustment->id, 'created', $user->id,
                details: $adjustment->adj_number, reason: $adjustment->reason,
                newValue: ['adj_number' => $adjustment->adj_number, 'lines' => count($data['lines'])]);

            return $adjustment;
        });

        return response()->json($this->present($adjustment->fresh('lines')), 201);
    }

    /** Submit for approval (INV-001). Still changes no stock. */
    public function submit(Request $request, string $adjustmentId)
    {
        $user = $this->writer($request);
        $adjustment = $this->adjustmentOrFail($user, $adjustmentId);

        if ($adjustment->status !== StockAdjustment::STATUS_DRAFT) {
            throw new ApiException(400, 'Only draft adjustments can be submitted');
        }
        $adjustment->status = StockAdjustment::STATUS_PENDING_APPROVAL;
        Audit::record('stock_adjustment', $adjustment->id, 'submitted_for_approval', $user->id,
            details: $adjustment->adj_number,
            oldValue: ['status' => StockAdjustment::STATUS_DRAFT],
            newValue: ['status' => $adjustment->status]);
        $adjustment->save();

        return response()->json($this->present($adjustment->fresh('lines')));
    }

    /** INV-001: manager approves -- and only now do the stock changes take effect. */
    public function approve(Request $request, string $adjustmentId)
    {
        $user = $this->writer($request);
        $adjustment = $this->adjustmentOrFail($user, $adjustmentId);

        try {
            // One transaction across every line: if any line would
            // overdraw its warehouse, the whole approval is rolled
            // back, including the lines already applied and the status
            // change -- the same all-or-nothing the Python router gets
            // from its single commit.
            DB::transaction(function () use ($adjustment, $user) {
                InventoryService::approveAdjustment($adjustment, $user->id);
                $adjustment->save();
                Audit::record('stock_adjustment', $adjustment->id, 'approved', $user->id,
                    details: $adjustment->adj_number, reason: $adjustment->reason,
                    oldValue: ['status' => StockAdjustment::STATUS_PENDING_APPROVAL],
                    newValue: ['status' => $adjustment->status, 'approved_by' => $user->id]);
            });
        } catch (InventoryRuleViolation $e) {
            throw new ApiException(400, $e->getMessage());
        }

        return response()->json($this->present($adjustment->fresh('lines')));
    }

    /** Rejected: no stock change, ever. The document stays for the record -- never deleted. */
    public function reject(Request $request, string $adjustmentId)
    {
        $user = $this->writer($request);
        $adjustment = $this->adjustmentOrFail($user, $adjustmentId);

        if ($adjustment->status !== StockAdjustment::STATUS_PENDING_APPROVAL) {
            throw new ApiException(400, 'Only pending adjustments can be rejected');
        }
        $adjustment->status = StockAdjustment::STATUS_REJECTED;
        Audit::record('stock_adjustment', $adjustment->id, 'rejected', $user->id,
            details: $adjustment->adj_number,
            oldValue: ['status' => StockAdjustment::STATUS_PENDING_APPROVAL],
            newValue: ['status' => $adjustment->status]);
        $adjustment->save();

        return response()->json($this->present($adjustment->fresh('lines')));
    }

    private function writer(Request $request): User
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        return $user;
    }

    private function adjustmentOrFail(User $user, string $adjustmentId): StockAdjustment
    {
        $adjustment = StockAdjustment::with('lines')->find($adjustmentId);
        if (! $adjustment || $adjustment->company_id !== $user->company_id) {
            throw new ApiException(404, 'Adjustment not found');
        }

        return $adjustment;
    }

    /** ADJ-00001, ... -- same count-based scheme as the Python router's `_next_adj`. */
    private function nextAdjustmentNumber(string $companyId): string
    {
        $count = StockAdjustment::where('company_id', $companyId)->count();

        return 'ADJ-'.str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
    }

    /** @return array<string, mixed> */
    private function present(StockAdjustment $adjustment): array
    {
        return [
            'id' => $adjustment->id,
            'company_id' => $adjustment->company_id,
            'adj_number' => $adjustment->adj_number,
            'warehouse_id' => $adjustment->warehouse_id,
            'adjustment_date' => optional($adjustment->adjustment_date)->toJSON(),
            'reason' => $adjustment->reason,
            'status' => $adjustment->status,
            'approved_by' => $adjustment->approved_by,
            'approved_at' => optional($adjustment->approved_at)->toJSON(),
            'created_by' => $adjustment->created_by,
            'created_at' => optional($adjustment->created_at)->toJSON(),
            'lines' => $adjustment->lines->map(fn (StockAdjustmentLine $l) => [
                'id' => $l->id,
                'stock_item_id' => $l->stock_item_id,
                'quantity_change' => (int) $l->quantity_change,
                'notes' => $l->notes,
            ])->values(),
        ];
    }
}
