<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\CompanyIndividual;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Warehouse;

/**
 * Multi-company scoping for the four stock movement documents (GRN,
 * GTN, GRTN, Stock Adjustment).
 *
 * HARDENING -- `backend/` does not do this. The Python router assigns
 * `warehouse_id` / `stock_item_id` / `supplier_id` straight from the
 * request body onto the document; the database foreign key proves the
 * row exists, but nothing proves it belongs to the caller's own
 * company. Left as-is, a user could receive stock into another
 * company's warehouse, or move another company's item, and the
 * resulting stock level would carry THEIR company_id (the services
 * take it from the document) while pointing at a foreign warehouse --
 * corrupting both companies' stock reports.
 *
 * CLAUDE.md requires multi-company scoping and both-ends validation,
 * so every referenced id is checked here and refused with a 404 (the
 * same shape every other module in this backend uses for "not yours").
 * Recorded as a deliberate divergence in docs/php-conversion-plan.md.
 */
class StockDocumentGuard
{
    public static function assertWarehouse(User $user, string $warehouseId): Warehouse
    {
        $warehouse = Warehouse::find($warehouseId);
        if (! $warehouse || $warehouse->company_id !== $user->company_id) {
            throw new ApiException(404, 'Warehouse not found');
        }

        return $warehouse;
    }

    /**
     * Every line's stock item must belong to this company.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function assertStockItems(User $user, array $lines): void
    {
        $ids = array_values(array_unique(array_filter(array_column($lines, 'stock_item_id'))));
        if ($ids === []) {
            return;
        }
        $found = StockItem::whereIn('id', $ids)->where('company_id', $user->company_id)->count();
        if ($found !== count($ids)) {
            throw new ApiException(404, 'Stock item not found');
        }
    }

    /**
     * A supplier is a CompanyIndividual in this company (2026-09-12:
     * suppliers live in the customer master, not a separate table).
     * The `is_supplier` flag itself is NOT required here -- neither
     * backend enforces it on a stock document, and requiring it would
     * be a new rule.
     */
    public static function assertSupplier(User $user, string $supplierId): CompanyIndividual
    {
        $supplier = CompanyIndividual::find($supplierId);
        if (! $supplier || $supplier->company_id !== $user->company_id) {
            throw new ApiException(404, 'Company / Individual not found');
        }

        return $supplier;
    }
}
