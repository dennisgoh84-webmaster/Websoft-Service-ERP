<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Exceptions\InventoryRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\GoodsTransferNote;
use App\Models\GoodsTransferNoteLine;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\InventoryService;
use App\Services\StockDocumentGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Goods Transfer Note (GTN): moving stock between two warehouses.
 * Mirrors the `/api/stock/gtn` half of backend/app/routers/stock.py.
 *
 * RBAC: `goods_transfer_note` -- VIEW to list, FULL to create/confirm.
 *
 * Confirming deducts at the source and receives at the destination in
 * one transaction, carrying the source's weighted average cost across
 * (a transfer moves stock, it never revalues it) -- see
 * App\Services\InventoryService::confirmGtn. A transfer of more than
 * the source holds is refused outright, so stock never goes negative.
 *
 * There is no `GET /gtn/{id}` in either backend -- the Goods Transfer
 * Note screen reads the list. Not silently added here.
 */
class GoodsTransferNoteController extends Controller
{
    private const MODULE = 'goods_transfer_note';

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return GoodsTransferNote::with('lines')
            ->where('company_id', $user->company_id)
            ->orderByDesc('created_at')->get()
            ->map(fn (GoodsTransferNote $g) => $this->present($g))->values();
    }

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $data = $request->validate([
            'from_warehouse_id' => 'required|uuid',
            'to_warehouse_id' => 'required|uuid',
            'transfer_date' => 'sometimes|nullable|date',
            'notes' => 'sometimes|nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.stock_item_id' => 'required|uuid',
            'lines.*.quantity' => 'required|integer|gt:0',
            'lines.*.notes' => 'sometimes|nullable|string',
        ]);

        if ($data['from_warehouse_id'] === $data['to_warehouse_id']) {
            throw new ApiException(400, 'Source and destination warehouse must be different');
        }
        StockDocumentGuard::assertWarehouse($user, $data['from_warehouse_id']);
        StockDocumentGuard::assertWarehouse($user, $data['to_warehouse_id']);
        StockDocumentGuard::assertStockItems($user, $data['lines']);

        $gtn = DB::transaction(function () use ($user, $data) {
            $gtn = GoodsTransferNote::create([
                'company_id' => $user->company_id,
                'gtn_number' => $this->nextGtnNumber($user->company_id),
                'from_warehouse_id' => $data['from_warehouse_id'],
                'to_warehouse_id' => $data['to_warehouse_id'],
                'transfer_date' => $data['transfer_date'] ?? now(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($data['lines'] as $line) {
                GoodsTransferNoteLine::create([
                    'gtn_id' => $gtn->id,
                    'stock_item_id' => $line['stock_item_id'],
                    'quantity' => $line['quantity'],
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            Audit::record('goods_transfer_note', $gtn->id, 'created', $user->id,
                details: $gtn->gtn_number,
                newValue: ['gtn_number' => $gtn->gtn_number, 'lines' => count($data['lines'])]);

            return $gtn;
        });

        return response()->json($this->present($gtn->fresh('lines')), 201);
    }

    public function confirm(Request $request, string $gtnId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $gtn = GoodsTransferNote::with('lines')->find($gtnId);
        if (! $gtn || $gtn->company_id !== $user->company_id) {
            throw new ApiException(404, 'GTN not found');
        }

        try {
            DB::transaction(function () use ($gtn, $user) {
                InventoryService::confirmGtn($gtn, $user->id);
                $gtn->save();
                Audit::record('goods_transfer_note', $gtn->id, 'confirmed', $user->id,
                    details: $gtn->gtn_number,
                    oldValue: ['status' => GoodsTransferNote::STATUS_DRAFT],
                    newValue: ['status' => $gtn->status]);
            });
        } catch (InventoryRuleViolation $e) {
            throw new ApiException(400, $e->getMessage());
        }

        return response()->json($this->present($gtn->fresh('lines')));
    }

    /** GTN-00001, ... -- same count-based scheme as the Python router's `_next_gtn`; see GoodsReceiveNoteController. */
    private function nextGtnNumber(string $companyId): string
    {
        $count = GoodsTransferNote::where('company_id', $companyId)->count();

        return 'GTN-'.str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
    }

    /** @return array<string, mixed> */
    private function present(GoodsTransferNote $gtn): array
    {
        return [
            'id' => $gtn->id,
            'company_id' => $gtn->company_id,
            'gtn_number' => $gtn->gtn_number,
            'from_warehouse_id' => $gtn->from_warehouse_id,
            'to_warehouse_id' => $gtn->to_warehouse_id,
            'transfer_date' => optional($gtn->transfer_date)->toJSON(),
            'status' => $gtn->status,
            'notes' => $gtn->notes,
            'created_by' => $gtn->created_by,
            'created_at' => optional($gtn->created_at)->toJSON(),
            'lines' => $gtn->lines->map(fn (GoodsTransferNoteLine $l) => [
                'id' => $l->id,
                'stock_item_id' => $l->stock_item_id,
                'quantity' => (int) $l->quantity,
                'notes' => $l->notes,
            ])->values(),
        ];
    }
}
