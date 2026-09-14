<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Exceptions\InventoryRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\GoodsReceiveNote;
use App\Models\GoodsReceiveNoteLine;
use App\Models\User;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\InventoryService;
use App\Services\StockDocumentGuard;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Goods Receive Note (GRN): receipt of goods from a supplier into a
 * warehouse. Mirrors the `/api/stock/grn` half of
 * backend/app/routers/stock.py.
 *
 * RBAC: `goods_receive_note` -- VIEW to list/read, FULL to create or
 * confirm -- its own Module Control key, so warehouse staff can be
 * given receiving rights without Stock Adjustment approval rights.
 *
 * Confirming is the only event that brings new cost information into
 * the system, so it is the only one that moves the INV-002 weighted
 * average -- the arithmetic lives in App\Services\InventoryService,
 * not here.
 *
 * KNOWN GAP (carried over from `backend/`, not introduced here): a
 * confirmed GRN posts NOTHING to the General Ledger. The Python
 * service has no goods-receipt posting either, and Accounts Payable
 * matches a supplier bill against the Purchase Order only (PUR-002,
 * 2-way matching -- "a separate Goods Receipt match is not required"),
 * so there is no confirmed rule saying a receipt should post a
 * Dr Inventory / Cr GRNI entry. Inventing one would be inventing a
 * business rule. Flagged in docs/php-conversion-plan.md.
 */
class GoodsReceiveNoteController extends Controller
{
    private const MODULE = 'goods_receive_note';

    public function index(Request $request)
    {
        $user = $this->viewer($request);

        return GoodsReceiveNote::with('lines')
            ->where('company_id', $user->company_id)
            ->orderByDesc('created_at')->get()
            ->map(fn (GoodsReceiveNote $g) => $this->present($g))->values();
    }

    public function show(Request $request, string $grnId)
    {
        $user = $this->viewer($request);

        return response()->json($this->present($this->grnOrFail($user, $grnId)));
    }

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $data = $request->validate([
            'warehouse_id' => 'required|uuid',
            'supplier_id' => 'sometimes|nullable|uuid',
            'purchase_order_id' => 'sometimes|nullable|uuid',
            'receive_date' => 'sometimes|nullable|date',
            'notes' => 'sometimes|nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.stock_item_id' => 'required|uuid',
            'lines.*.quantity' => 'required|integer|gt:0',
            'lines.*.unit_cost' => 'required|numeric|min:0',
            'lines.*.notes' => 'sometimes|nullable|string',
        ]);

        StockDocumentGuard::assertWarehouse($user, $data['warehouse_id']);
        StockDocumentGuard::assertStockItems($user, $data['lines']);
        if (! empty($data['supplier_id'])) {
            StockDocumentGuard::assertSupplier($user, $data['supplier_id']);
        }

        $grn = DB::transaction(function () use ($user, $data) {
            $grn = GoodsReceiveNote::create([
                'company_id' => $user->company_id,
                'grn_number' => $this->nextGrnNumber($user->company_id),
                'warehouse_id' => $data['warehouse_id'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'receive_date' => $data['receive_date'] ?? now(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($data['lines'] as $line) {
                $unitCost = Money::of($line['unit_cost']);
                GoodsReceiveNoteLine::create([
                    'grn_id' => $grn->id,
                    'stock_item_id' => $line['stock_item_id'],
                    'quantity' => $line['quantity'],
                    // 4dp unit cost, 2dp extended total -- the two
                    // precisions the Python columns use.
                    'unit_cost' => $unitCost->toString(4),
                    'total_cost' => $unitCost->multipliedBy($line['quantity'])->quantize()->toString(),
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            Audit::record('goods_receive_note', $grn->id, 'created', $user->id,
                details: $grn->grn_number,
                newValue: ['grn_number' => $grn->grn_number, 'lines' => count($data['lines'])]);

            return $grn;
        });

        return response()->json($this->present($grn->fresh('lines')), 201);
    }

    public function confirm(Request $request, string $grnId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $grn = $this->grnOrFail($user, $grnId);

        try {
            // One transaction for the whole document: confirming must
            // be all-or-nothing across every line.
            DB::transaction(function () use ($grn, $user) {
                InventoryService::confirmGrn($grn, $user->id);
                $grn->save();
                Audit::record('goods_receive_note', $grn->id, 'confirmed', $user->id,
                    details: $grn->grn_number,
                    oldValue: ['status' => GoodsReceiveNote::STATUS_DRAFT],
                    newValue: ['status' => $grn->status]);
            });
        } catch (InventoryRuleViolation $e) {
            throw new ApiException(400, $e->getMessage());
        }

        return response()->json($this->present($grn->fresh('lines')));
    }

    private function viewer(Request $request): User
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $user;
    }

    private function grnOrFail(User $user, string $grnId): GoodsReceiveNote
    {
        $grn = GoodsReceiveNote::with('lines')->find($grnId);
        if (! $grn || $grn->company_id !== $user->company_id) {
            throw new ApiException(404, 'GRN not found');
        }

        return $grn;
    }

    /**
     * GRN-00001, GRN-00002, ... Counts this company's existing GRNs and
     * adds one -- copied exactly from the Python router's `_next_grn`.
     *
     * QUIRK PRESERVED, not silently "fixed": the stock router does its
     * own count-based numbering instead of using
     * backend/app/services/numbering.py (whose PREFIXES table has no
     * grn/gtn/grtn/adj entry at all, and whose counter row is locked
     * for the transaction). A count can repeat a number if a document
     * is ever removed, and two simultaneous creates could collide.
     * Changing the format would change the document numbers users
     * already see, so it is kept identical here and flagged in
     * docs/php-conversion-plan.md as worth raising with Dennis.
     */
    private function nextGrnNumber(string $companyId): string
    {
        $count = GoodsReceiveNote::where('company_id', $companyId)->count();

        return 'GRN-'.str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
    }

    /** @return array<string, mixed> */
    private function present(GoodsReceiveNote $grn): array
    {
        return [
            'id' => $grn->id,
            'company_id' => $grn->company_id,
            'grn_number' => $grn->grn_number,
            'warehouse_id' => $grn->warehouse_id,
            'supplier_id' => $grn->supplier_id,
            'purchase_order_id' => $grn->purchase_order_id,
            'receive_date' => optional($grn->receive_date)->toJSON(),
            'status' => $grn->status,
            'notes' => $grn->notes,
            'created_by' => $grn->created_by,
            'created_at' => optional($grn->created_at)->toJSON(),
            'lines' => $grn->lines->map(fn (GoodsReceiveNoteLine $l) => [
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
