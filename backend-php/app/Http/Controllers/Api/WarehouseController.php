<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Audit;
use App\Services\Authority;
use Illuminate\Http\Request;

/**
 * Warehouses (stock locations). Mirrors the `/api/stock/warehouses`
 * half of backend/app/routers/stock.py.
 *
 * RBAC: `stock_master`, VIEW to list, FULL to create/update --
 * identical to the Python router.
 *
 * A warehouse is never deleted (there is no DELETE route in either
 * backend); it is deactivated, so the stock levels and movements that
 * reference it stay readable.
 *
 * BUG FOUND AND FIXED (see docs/php-conversion-plan.md): the Python
 * PATCH route types its body as `WarehouseCreate`, whose `code` and
 * `name` are REQUIRED and which has no `is_active` field at all --
 * so `WarehousesPage.tsx`'s Activate/Deactivate button
 * (`updateWarehouse(id, { is_active: !w.is_active })`) can only ever
 * 422 against `backend/`, and could not have toggled the flag even if
 * it validated. Here every field is optional (which is what Python's
 * own `model_dump(exclude_unset=True)` update loop was written for)
 * and `is_active` is accepted, so the existing screen works.
 */
class WarehouseController extends Controller
{
    private const MODULE = 'stock_master';

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return Warehouse::where('company_id', $user->company_id)
            ->orderBy('code')->get()->map(fn (Warehouse $w) => $this->present($w))->values();
    }

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $data = $request->validate([
            'code' => 'required|string|max:20',
            'name' => 'required|string|max:200',
            'address' => 'sometimes|nullable|string',
        ]);

        $warehouse = Warehouse::create($data + ['company_id' => $user->company_id]);
        Audit::record('warehouse', $warehouse->id, 'created', $user->id,
            details: "{$warehouse->code} {$warehouse->name}",
            newValue: ['code' => $warehouse->code, 'name' => $warehouse->name, 'address' => $warehouse->address]);

        return response()->json($this->present($warehouse), 201);
    }

    public function update(Request $request, string $warehouseId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $warehouse = $this->warehouseOrFail($user, $warehouseId);
        $data = $request->validate([
            'code' => 'sometimes|required|string|max:20',
            'name' => 'sometimes|required|string|max:200',
            'address' => 'sometimes|nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $old = [
            'code' => $warehouse->code, 'name' => $warehouse->name,
            'address' => $warehouse->address, 'is_active' => $warehouse->is_active,
        ];
        $warehouse->fill($data);
        if ($warehouse->isDirty()) {
            // An activate/deactivate is logged under its own action so
            // Event Logs can tell it apart from an ordinary edit.
            $action = $warehouse->isDirty('is_active') && count($warehouse->getDirty()) === 1
                ? ($warehouse->is_active ? 'activated' : 'deactivated')
                : 'updated';
            Audit::record('warehouse', $warehouse->id, $action, $user->id, oldValue: $old, newValue: [
                'code' => $warehouse->code, 'name' => $warehouse->name,
                'address' => $warehouse->address, 'is_active' => $warehouse->is_active,
            ]);
        }
        $warehouse->save();

        return response()->json($this->present($warehouse->fresh()));
    }

    private function warehouseOrFail(User $user, string $warehouseId): Warehouse
    {
        $warehouse = Warehouse::find($warehouseId);
        if (! $warehouse || $warehouse->company_id !== $user->company_id) {
            throw new ApiException(404, 'Warehouse not found');
        }

        return $warehouse;
    }

    /** @return array<string, mixed> */
    private function present(Warehouse $warehouse): array
    {
        return [
            'id' => $warehouse->id,
            'company_id' => $warehouse->company_id,
            'code' => $warehouse->code,
            'name' => $warehouse->name,
            'address' => $warehouse->address,
            'is_active' => $warehouse->is_active,
            'created_at' => optional($warehouse->created_at)->toJSON(),
            'updated_at' => optional($warehouse->updated_at)->toJSON(),
        ];
    }
}
