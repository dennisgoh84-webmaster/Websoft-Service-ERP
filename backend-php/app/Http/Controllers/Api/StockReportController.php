<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ScopesReportCompanies;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\StockItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
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
 *
 * Every report takes `company_ids` -- one or several of the viewer's
 * own companies (2026-09-25); none = the signed-in company, which is
 * what the Stock Item Detail screen's movement list relies on.
 */
class StockReportController extends Controller
{
    use ScopesReportCompanies;

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
        $scope = $this->scope($request, $user);

        $query = StockMovement::whereIn('company_id', array_keys($scope));
        if ($request->filled('stock_item_id')) {
            $query->where('stock_item_id', $request->query('stock_item_id'));
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->query('warehouse_id'));
        }
        $limit = (int) $request->query('limit', (string) self::DEFAULT_MOVEMENT_LIMIT);

        $movements = $query->orderByDesc('created_at')->limit($limit)->get();
        $items = StockItem::whereIn('id', $movements->pluck('stock_item_id')->unique())->get(['id', 'code', 'name'])->keyBy('id');
        $warehouses = Warehouse::whereIn('id', $movements->pluck('warehouse_id')->unique())->get(['id', 'code', 'name'])->keyBy('id');

        return $movements
            ->map(fn (StockMovement $m) => [
                'id' => $m->id,
                'company_id' => $m->company_id,
                'company_name' => $scope[$m->company_id] ?? '',
                'item_code' => $items->get($m->stock_item_id)?->code,
                'item_name' => $items->get($m->stock_item_id)?->name,
                'warehouse_code' => $warehouses->get($m->warehouse_id)?->code,
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
        $scope = $this->scope($request, $user);

        $query = StockLevel::query()
            ->join('stock_items', 'stock_levels.stock_item_id', '=', 'stock_items.id')
            ->join('warehouses', 'stock_levels.warehouse_id', '=', 'warehouses.id')
            ->whereIn('stock_levels.company_id', array_keys($scope))
            // Only positive holdings are valued, same as Python.
            ->where('stock_levels.quantity', '>', 0)
            ->select(
                'stock_levels.company_id',
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
        // Across several companies, keep each company's stock together.
        $order = array_flip(array_keys($scope));
        $rows = $rows->sortBy(fn ($r) => $order[$r->company_id] ?? 0)->values();

        $totalValue = Money::of(0);
        $items = [];
        foreach ($rows as $row) {
            $value = Money::of((int) $row->quantity)->multipliedByMoney(Money::of($row->avg_cost));
            $totalValue = $totalValue->plus($value);
            $items[] = [
                'company_id' => $row->company_id,
                'company_name' => $scope[$row->company_id] ?? '',
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
        $scope = $this->scope($request, $user);
        $order = array_flip(array_keys($scope));

        $items = StockItem::whereIn('company_id', array_keys($scope))
            ->where('is_active', true)
            ->where('reorder_level', '>', 0)
            ->orderBy('code')
            ->get()
            ->sortBy(fn (StockItem $i) => $order[$i->company_id] ?? 0)
            ->values();

        $result = [];
        foreach ($items as $item) {
            $currentStock = (int) StockLevel::where('stock_item_id', $item->id)->sum('quantity');
            if ($currentStock <= $item->reorder_level) {
                $result[] = [
                    'company_id' => $item->company_id,
                    'company_name' => $scope[$item->company_id] ?? '',
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

    /**
     * Warehouse and item choices across the ticked internal companies
     * (labelled with the company code when there are several).
     */
    public function filterOptions(Request $request)
    {
        $user = $this->viewer($request);
        $scope = $this->scope($request, $user);
        $ids = array_keys($scope);
        $label = fn ($r) => ['id' => $r->id, 'name' => $this->scopedLabel($scope, "{$r->code} – {$r->name}", $r->company_id)];

        return response()->json([
            'warehouses' => Warehouse::whereIn('company_id', $ids)->orderBy('code')->get(['id', 'code', 'name', 'company_id'])->map($label)->values(),
            'items' => StockItem::whereIn('company_id', $ids)->orderBy('code')->get(['id', 'code', 'name', 'company_id'])->map($label)->values(),
        ]);
    }

    /** @return array<string, string> */
    private function scope(Request $request, User $user): array
    {
        return $this->reportCompanyScope($request, $user, self::MODULE, 'Stock Operation Reports');
    }

    private function viewer(Request $request): User
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $user;
    }
}
