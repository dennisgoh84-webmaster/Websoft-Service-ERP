<?php

namespace App\Services;

use App\Exceptions\InventoryRuleViolation;
use App\Models\GoodsReceiveNote;
use App\Models\GoodsReturnNote;
use App\Models\GoodsTransferNote;
use App\Models\StockAdjustment;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Support\Money;
use App\Support\StockDocumentStatus;
use Illuminate\Support\Carbon;

/**
 * Stock / Inventory business logic. Mirrors
 * backend/app/services/inventory.py 1:1 -- same function names
 * (camelCased), same order, same arithmetic, same error messages.
 *
 * INV-002 (CONFIRMED 2026-09-09): inventory is valued using WEIGHTED
 * AVERAGE COST -- the cost per unit is the average cost of all units
 * currently in stock. When stock is received (GRN), the average cost
 * at that warehouse is recalculated:
 *
 *   new_avg = (existing_qty * existing_avg + received_qty * received_cost)
 *             / (existing_qty + received_qty)
 *
 * When stock leaves (transfer out, return, adjustment down) the average
 * cost stays the same at the source location -- units leave at their
 * current weighted average, which is what makes the average "weighted"
 * rather than re-derived.
 *
 * INV-001 (CONFIRMED 2026-09-09): stock adjustments require manager
 * approval. The adjustment document must be approved before stock
 * levels change -- see approveAdjustment() below, the ONLY path by
 * which an adjustment ever touches a stock level.
 *
 * MONEY/COST PRECISION: a stock unit/average cost is Numeric(14, 4),
 * not the Numeric(12, 2) used for customer-facing money, so every
 * quantize here is to 4dp for a unit cost and 2dp for an extended
 * total -- exactly the two precisions the Python service quantizes to
 * (`Decimal("0.0001")` and `Decimal("0.01")`, both ROUND_HALF_UP).
 * All arithmetic goes through App\Support\Money (brick/math), never
 * raw PHP float math -- see docs/php-conversion-plan.md's
 * Decimal/money handling convention.
 *
 * Every method here assumes the caller has already opened a database
 * transaction, the same way the Python service relies on its router's
 * single commit: confirming a multi-line document must be all-or-
 * nothing, or a failure halfway through would leave some lines posted
 * and the rest not.
 */
class InventoryService
{
    /** Decimal places of a stock unit / weighted average cost (Numeric(14, 4)). */
    private const COST_SCALE = 4;

    /** Decimal places of an extended (quantity x cost) total (Numeric(14, 2)). */
    private const TOTAL_SCALE = 2;

    /** Get the existing stock level for this item/warehouse, or create a zero row. */
    public static function getOrCreateStockLevel(string $companyId, string $stockItemId, string $warehouseId): StockLevel
    {
        $level = StockLevel::where('company_id', $companyId)
            ->where('stock_item_id', $stockItemId)
            ->where('warehouse_id', $warehouseId)
            ->first();

        if (! $level) {
            $level = StockLevel::create([
                'company_id' => $companyId,
                'stock_item_id' => $stockItemId,
                'warehouse_id' => $warehouseId,
                'quantity' => 0,
                'avg_cost' => '0.0000',
            ]);
        }

        return $level;
    }

    /**
     * Add stock via a GRN. Recalculates the weighted average cost
     * (INV-002) -- this is the only movement that brings new cost
     * information in, so the only one that can move the average.
     *
     * @param  string|int|float  $unitCost  The cost this receipt comes in at.
     */
    public static function receiveStock(
        string $companyId,
        string $stockItemId,
        string $warehouseId,
        int $qty,
        string|int|float $unitCost,
        string $referenceType,
        string $referenceId,
        ?string $userId = null,
        ?string $notes = null,
    ): StockMovement {
        $level = self::getOrCreateStockLevel($companyId, $stockItemId, $warehouseId);
        $cost = Money::of($unitCost);

        // Weighted average cost. Both operands keep their full internal
        // precision until the single quantize at the end -- mirroring
        // how the Python Decimal arithmetic never rounds an
        // intermediate operand.
        $existingValue = Money::of($level->quantity)->multipliedByMoney(Money::of($level->avg_cost));
        $incomingValue = Money::of($qty)->multipliedByMoney($cost);
        $newQty = $level->quantity + $qty;
        if ($newQty > 0) {
            $level->avg_cost = $existingValue->plus($incomingValue)
                ->dividedBy($newQty)
                ->quantize(self::COST_SCALE)
                ->toString(self::COST_SCALE);
        }
        // A zero-or-negative resulting quantity leaves avg_cost exactly
        // as it was -- same guard as the Python service (there is no
        // meaningful average over no units, and inventing one would
        // corrupt the next receipt's weighting).
        $level->quantity = $newQty;
        $level->save();

        return StockMovement::create([
            'company_id' => $companyId,
            'stock_item_id' => $stockItemId,
            'warehouse_id' => $warehouseId,
            'movement_type' => StockMovement::TYPE_RECEIVE,
            'quantity' => $qty,
            'unit_cost' => $cost->toString(self::COST_SCALE),
            'total_cost' => $incomingValue->quantize(self::TOTAL_SCALE)->toString(self::TOTAL_SCALE),
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'notes' => $notes,
            'created_by' => $userId,
        ]);
    }

    /**
     * Remove stock (transfer out, return to supplier, adjustment down).
     * Uses the location's CURRENT weighted average cost as the movement's
     * unit cost, and leaves that average unchanged (INV-002).
     *
     * Stock can never go negative: the Python service refuses the whole
     * movement rather than allowing an overdraw, and so does this.
     */
    public static function deductStock(
        string $companyId,
        string $stockItemId,
        string $warehouseId,
        int $qty,
        string $movementType,
        string $referenceType,
        string $referenceId,
        ?string $userId = null,
        ?string $notes = null,
    ): StockMovement {
        $level = self::getOrCreateStockLevel($companyId, $stockItemId, $warehouseId);
        if ($level->quantity < $qty) {
            throw new InventoryRuleViolation("Insufficient stock: have {$level->quantity}, need {$qty}");
        }

        $unitCost = Money::of($level->avg_cost);
        $totalCost = Money::of($qty)->multipliedByMoney($unitCost)->quantize(self::TOTAL_SCALE);
        $level->quantity -= $qty;
        // avg_cost stays the same when deducting.
        $level->save();

        return StockMovement::create([
            'company_id' => $companyId,
            'stock_item_id' => $stockItemId,
            'warehouse_id' => $warehouseId,
            'movement_type' => $movementType,
            'quantity' => -$qty,
            'unit_cost' => $unitCost->toString(self::COST_SCALE),
            'total_cost' => $totalCost->toString(self::TOTAL_SCALE),
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'notes' => $notes,
            'created_by' => $userId,
        ]);
    }

    /**
     * Positive adjustment -- add quantity at the current average cost.
     * An adjustment carries no new cost information (it records a count
     * variance or a found item, not a purchase), so unlike receiveStock
     * this deliberately does NOT move the weighted average.
     */
    public static function adjustStockIncrease(
        string $companyId,
        string $stockItemId,
        string $warehouseId,
        int $qty,
        string $referenceId,
        ?string $userId = null,
        ?string $notes = null,
    ): StockMovement {
        $level = self::getOrCreateStockLevel($companyId, $stockItemId, $warehouseId);
        $level->quantity += $qty;
        $level->save();

        $unitCost = Money::of($level->avg_cost);

        return StockMovement::create([
            'company_id' => $companyId,
            'stock_item_id' => $stockItemId,
            'warehouse_id' => $warehouseId,
            'movement_type' => StockMovement::TYPE_ADJUSTMENT,
            'quantity' => $qty,
            'unit_cost' => $unitCost->toString(self::COST_SCALE),
            'total_cost' => Money::of($qty)->multipliedByMoney($unitCost)
                ->quantize(self::TOTAL_SCALE)->toString(self::TOTAL_SCALE),
            'reference_type' => 'adj',
            'reference_id' => $referenceId,
            'notes' => $notes,
            'created_by' => $userId,
        ]);
    }

    // ── GRN confirm ─────────────────────────────────────────────────

    /** Confirm a Goods Receive Note: update stock levels for every line. */
    public static function confirmGrn(GoodsReceiveNote $grn, ?string $userId = null): void
    {
        if ($grn->status !== StockDocumentStatus::DRAFT) {
            throw new InventoryRuleViolation('GRN is not in draft status');
        }
        foreach ($grn->lines as $line) {
            self::receiveStock(
                $grn->company_id, $line->stock_item_id, $grn->warehouse_id,
                $line->quantity, $line->unit_cost,
                referenceType: 'grn', referenceId: $grn->id,
                userId: $userId,
            );
        }
        $grn->status = StockDocumentStatus::CONFIRMED;
    }

    // ── GTN confirm ─────────────────────────────────────────────────

    /** Confirm a Goods Transfer Note: deduct from the source warehouse, add to the destination. */
    public static function confirmGtn(GoodsTransferNote $gtn, ?string $userId = null): void
    {
        if ($gtn->status !== StockDocumentStatus::DRAFT) {
            throw new InventoryRuleViolation('GTN is not in draft status');
        }
        foreach ($gtn->lines as $line) {
            // Read the average cost at the source BEFORE deducting, so
            // the units arrive at the destination carrying the cost
            // they left with. A transfer moves stock; it must not
            // revalue it.
            $sourceLevel = self::getOrCreateStockLevel($gtn->company_id, $line->stock_item_id, $gtn->from_warehouse_id);
            $transferCost = (string) $sourceLevel->avg_cost;

            self::deductStock(
                $gtn->company_id, $line->stock_item_id, $gtn->from_warehouse_id,
                $line->quantity, StockMovement::TYPE_TRANSFER_OUT,
                referenceType: 'gtn', referenceId: $gtn->id,
                userId: $userId,
            );
            self::receiveStock(
                $gtn->company_id, $line->stock_item_id, $gtn->to_warehouse_id,
                $line->quantity, $transferCost,
                referenceType: 'gtn', referenceId: $gtn->id,
                userId: $userId,
            );
        }
        $gtn->status = StockDocumentStatus::CONFIRMED;
    }

    // ── GRTN confirm ────────────────────────────────────────────────

    /** Confirm a Goods Return Note: deduct the returned stock from the warehouse. */
    public static function confirmGrtn(GoodsReturnNote $grtn, ?string $userId = null): void
    {
        if ($grtn->status !== StockDocumentStatus::DRAFT) {
            throw new InventoryRuleViolation('GRTN is not in draft status');
        }
        foreach ($grtn->lines as $line) {
            // Note: deducted at the warehouse's weighted average cost,
            // not at the line's own unit_cost (what is claimed back
            // from the supplier). Same as the Python service.
            self::deductStock(
                $grtn->company_id, $line->stock_item_id, $grtn->warehouse_id,
                $line->quantity, StockMovement::TYPE_RETURN_OUT,
                referenceType: 'grtn', referenceId: $grtn->id,
                userId: $userId,
            );
        }
        $grtn->status = StockDocumentStatus::CONFIRMED;
    }

    // ── Stock Adjustment approve ────────────────────────────────────

    /**
     * INV-001: approve a stock adjustment and apply the stock changes.
     * This is the ONLY place an adjustment ever moves stock -- a draft
     * or a rejected adjustment never touches a stock level, which is
     * what "requires manager approval before taking effect" means.
     */
    public static function approveAdjustment(StockAdjustment $adjustment, string $approverId): void
    {
        if ($adjustment->status !== StockAdjustment::STATUS_PENDING_APPROVAL) {
            throw new InventoryRuleViolation('Adjustment must be pending approval');
        }

        $adjustment->status = StockAdjustment::STATUS_APPROVED;
        $adjustment->approved_by = $approverId;
        $adjustment->approved_at = Carbon::now('UTC');

        foreach ($adjustment->lines as $line) {
            if ($line->quantity_change > 0) {
                self::adjustStockIncrease(
                    $adjustment->company_id, $line->stock_item_id, $adjustment->warehouse_id,
                    $line->quantity_change, $adjustment->id, $approverId, $line->notes,
                );
            } elseif ($line->quantity_change < 0) {
                self::deductStock(
                    $adjustment->company_id, $line->stock_item_id, $adjustment->warehouse_id,
                    abs($line->quantity_change), StockMovement::TYPE_ADJUSTMENT,
                    referenceType: 'adj', referenceId: $adjustment->id,
                    userId: $approverId, notes: $line->notes,
                );
            }
            // A zero quantity_change moves nothing and writes no
            // movement row -- same as the Python service, which has no
            // branch for it.
        }
    }
}
