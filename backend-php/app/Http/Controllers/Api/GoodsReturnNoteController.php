<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Exceptions\InventoryRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\GoodsReturnNote;
use App\Models\GoodsReturnNoteLine;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\InventoryService;
use App\Services\StockDocumentGuard;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Goods Return Note (GRTN): returning goods to a supplier. Mirrors the
 * `/api/stock/grtn` half of backend/app/routers/stock.py.
 *
 * RBAC: `goods_return_note` -- VIEW to list, FULL to create/confirm.
 *
 * Confirming deducts the returned quantity at the warehouse's current
 * weighted average cost -- NOT at the line's own `unit_cost`, which
 * records what is being claimed back from the supplier and can
 * legitimately differ. Faithful to the Python service.
 *
 * KNOWN GAP (carried over from `backend/`): a confirmed GRTN raises no
 * supplier credit note and posts nothing to the General Ledger. Neither
 * does the Python version, and no confirmed rule says it should.
 */
class GoodsReturnNoteController extends Controller
{
    private const MODULE = 'goods_return_note';

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return GoodsReturnNote::with('lines')
            ->where('company_id', $user->company_id)
            ->orderByDesc('created_at')->get()
            ->map(fn (GoodsReturnNote $g) => $this->present($g))->values();
    }

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $data = $request->validate([
            'warehouse_id' => 'required|uuid',
            'supplier_id' => 'sometimes|nullable|uuid',
            'return_date' => 'sometimes|nullable|date',
            'reason' => 'sometimes|nullable|string',
            'notes' => 'sometimes|nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.stock_item_id' => 'required|uuid',
            'lines.*.quantity' => 'required|integer|gt:0',
            // Defaults to 0 when omitted, matching GRTNLineCreate's own
            // `unit_cost: float = Field(ge=0, default=0)`.
            'lines.*.unit_cost' => 'sometimes|numeric|min:0',
            'lines.*.notes' => 'sometimes|nullable|string',
        ]);

        StockDocumentGuard::assertWarehouse($user, $data['warehouse_id']);
        StockDocumentGuard::assertStockItems($user, $data['lines']);
        if (! empty($data['supplier_id'])) {
            StockDocumentGuard::assertSupplier($user, $data['supplier_id']);
        }

        $grtn = DB::transaction(function () use ($user, $data) {
            $grtn = GoodsReturnNote::create([
                'company_id' => $user->company_id,
                'grtn_number' => $this->nextGrtnNumber($user->company_id),
                'warehouse_id' => $data['warehouse_id'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'return_date' => $data['return_date'] ?? now(),
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($data['lines'] as $line) {
                $unitCost = Money::of($line['unit_cost'] ?? 0);
                GoodsReturnNoteLine::create([
                    'grtn_id' => $grtn->id,
                    'stock_item_id' => $line['stock_item_id'],
                    'quantity' => $line['quantity'],
                    'unit_cost' => $unitCost->toString(4),
                    'total_cost' => $unitCost->multipliedBy($line['quantity'])->quantize()->toString(),
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            Audit::record('goods_return_note', $grtn->id, 'created', $user->id,
                details: $grtn->grtn_number,
                newValue: ['grtn_number' => $grtn->grtn_number, 'lines' => count($data['lines'])]);

            return $grtn;
        });

        return response()->json($this->present($grtn->fresh('lines')), 201);
    }

    public function confirm(Request $request, string $grtnId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $grtn = GoodsReturnNote::with('lines')->find($grtnId);
        if (! $grtn || $grtn->company_id !== $user->company_id) {
            throw new ApiException(404, 'GRTN not found');
        }

        try {
            DB::transaction(function () use ($grtn, $user) {
                InventoryService::confirmGrtn($grtn, $user->id);
                $grtn->save();
                Audit::record('goods_return_note', $grtn->id, 'confirmed', $user->id,
                    details: $grtn->grtn_number,
                    oldValue: ['status' => GoodsReturnNote::STATUS_DRAFT],
                    newValue: ['status' => $grtn->status]);
            });
        } catch (InventoryRuleViolation $e) {
            throw new ApiException(400, $e->getMessage());
        }

        return response()->json($this->present($grtn->fresh('lines')));
    }

    /** GRTN-00001, ... -- same count-based scheme as the Python router's `_next_grtn`. */
    private function nextGrtnNumber(string $companyId): string
    {
        $count = GoodsReturnNote::where('company_id', $companyId)->count();

        return 'GRTN-'.str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
    }

    /** @return array<string, mixed> */
    private function present(GoodsReturnNote $grtn): array
    {
        return [
            'id' => $grtn->id,
            'company_id' => $grtn->company_id,
            'grtn_number' => $grtn->grtn_number,
            'warehouse_id' => $grtn->warehouse_id,
            'supplier_id' => $grtn->supplier_id,
            'return_date' => optional($grtn->return_date)->toJSON(),
            'reason' => $grtn->reason,
            'status' => $grtn->status,
            'notes' => $grtn->notes,
            'created_by' => $grtn->created_by,
            'created_at' => optional($grtn->created_at)->toJSON(),
            'lines' => $grtn->lines->map(fn (GoodsReturnNoteLine $l) => [
                'id' => $l->id,
                'stock_item_id' => $l->stock_item_id,
                'quantity' => (int) $l->quantity,
                'unit_cost' => (float) $l->unit_cost,
                'total_cost' => (float) $l->total_cost,
                'notes' => $l->notes,
            ])->values(),
        ];
    }
}
