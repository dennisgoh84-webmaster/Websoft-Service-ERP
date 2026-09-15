<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\StockItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Authority;
use App\Support\Money;
use Illuminate\Http\Request;

/**
 * Stock operation reports: the stock movements journal, the INV-002
 * valuation report, and the reorder report. Mirrors the
 * `/api/stock/movements` and `/api/stock/reports/*` half of
 * backend/app/routers/stock.py.
 *
 * RBAC: `stock_operation_reports`, VIEW on every route -- its own
 * Module Control key, so a manager can be given the stock reports
 * without any rights to move stock. Note the movements journal lives
 * here, not under `stock_master`, exactly as in the Python router.
 *
 * Everything here is read-only: the reports derive from `stock_levels`
 * and `stock_movements`, never recompute or correct them.
 *
 * NOT converted (same gap as every other module): CSV/Excel export --
 * the Python router has none for stock either, so nothing is missing
 * relative to `backend/`.
 */
class StockReportController extends Controller
{
    private const MODULE = 'stock_operation_reports';

    /** Same default page size as the Python route's `limit: int = 200`. */
    private const DEFAULT_MOVEMENT_LIMIT = 200;

    /**
     * The stock movements journal -- every quantity change, newest
     * first. The Stock Item Detail screen reads it filtered by item.
     */
    public function movements(Request $request)
    {
        $user = $this->viewer($request);

        $query = StockMovement::where('company_id', $user->company_id);
        if ($request->filled('stock_item_id')) {
            $query->where('stock_item_id', $request->query('stock_item_id'));
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->query('warehouse_id'));
        }
        $limit = (int) $request->query('limit', (string) self::DEFAULT_MOVEMENT_LIMIT);

        return $query->orderByDesc('created_at')->limit($limit)->get()
            ->map(fn (StockMovement $m) => [
                'id' => $m->id,
                'stock_item_id' => $m->stock_item_id,
                'warehouse_id' => $m->warehouse_id,
                'movement_type' => $m->movement_type,
                'quantity' => (int) $m->quantity,
                // 4dp unit cost / 2dp extended total, as stored.
                'unit_cost' => (float) $m->unit_cost,
                'total_cost' => (float) $m->total_cost,
                'reference_type' => $m->reference_type,
                'reference_id' => $m->reference_id,
                'notes' => $m->notes,
                'created_at' => optional($m->created_at)->toJSON(),
            ])->values();
    }

    /**
     * INV-002 stock valuation: quantity x weighted average cost, per
     * item per warehouse, plus the total.
     *
     * The total is accumulated at FULL precision and quantized once at
     * the end -- matching the Python report, which sums raw Decimals
     * and only quantizes the final figure. Summing the already-rounded
     * per-row values instead would drift by cents on a large stock
     * holding.
     */
    public function valuation(Request $request)
    {
        $user = $this->viewer($request);

        $query = StockLevel::query()
            ->join('stock_items', 'stock_levels.stock_item_id', '=', 'stock_items.id')
            ->join('warehouses', 'stock_levels.warehouse_id', '=', 'warehouses.id')
            ->where('stock_levels.company_id', $user->company_id)
            // Only positive holdings are valued, same as Python.
            ->where('stock_levels.quantity', '>', 0)
            ->select(
                'stock_items.code as item_code',
                'stock_items.name as item_name',
                'warehouses.code as warehouse_code',
                'warehouses.name as warehouse_name',
                'stock_levels.quantity',
                // The weighted average is held once per item across all
                // locations (2026-09-15), so every warehouse row values
                // at the same unit cost -- these are slices of one
                // company-wide valuation, not independent ones.
                'stock_items.avg_cost',
            );

        if ($request->filled('warehouse_id')) {
            $query->where('stock_levels.warehouse_id', $request->query('warehouse_id'));
        }

        $rows = $query->orderBy('stock_items.code')->orderBy('warehouses.code')->get();

        $totalValue = Money::of(0);
        $items = [];
        foreach ($rows as $row) {
            $value = Money::of((int) $row->quantity)->multipliedByMoney(Money::of($row->avg_cost));
            $totalValue = $totalValue->plus($value);
            $items[] = [
                'item_code' => $row->item_code,
                'item_name' => $row->item_name,
                'warehouse_code' => $row->warehouse_code,
                'warehouse_name' => $row->warehouse_name,
                'quantity' => (int) $row->quantity,
                'avg_cost' => (float) $row->avg_cost,
                'total_value' => $value->quantize()->toFloat(),
            ];
        }

        return response()->json([
            'items' => $items,
            'total_value' => $totalValue->quantize()->toFloat(),
        ]);
    }

    /**
     * Items whose total stock across ALL warehouses is at or below
     * their reorder level. Only active items with a reorder level set
     * are considered -- an item with reorder_level 0 has not opted in,
     * and would otherwise be reported the moment it hit zero.
     */
    public function reorder(Request $request)
    {
        $user = $this->viewer($request);

        $items = StockItem::where('company_id', $user->company_id)
            ->where('is_active', true)
            ->where('reorder_level', '>', 0)
            ->orderBy('code')
            ->get();

        $result = [];
        foreach ($items as $item) {
            $currentStock = (int) StockLevel::where('stock_item_id', $item->id)->sum('quantity');
            if ($currentStock <= $item->reorder_level) {
                $result[] = [
                    'item_code' => $item->code,
                    'item_name' => $item->name,
                    'unit_of_measure' => $item->unit_of_measure,
                    'reorder_level' => (int) $item->reorder_level,
                    'current_stock' => $currentStock,
                    'shortfall' => (int) $item->reorder_level - $currentStock,
                ];
            }
        }

        return response()->json($result);
    }

    private function viewer(Request $request): User
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $user;
    }
}
