<?php

namespace App\Services;

use App\Exceptions\InventoryRuleViolation;
use App\Models\GoodsIssueNote;
use App\Models\GoodsReceiveNote;
use App\Models\GoodsReturnNote;
use App\Models\GoodsTransferNote;
use App\Models\StockAdjustment;
use App\Models\StockItem;
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
 * currently in stock.
 *
 * REVISED 2026-09-15 (Dennis): that average is held ONCE PER ITEM,
 * across all locations and branches, on `stock_items.avg_cost`, and
 * NOT per warehouse as backend/app/services/inventory.py holds it.
 * `stock_levels` carries quantity only. On receipt:
 *
 *   new_avg = (total_qty_all_locations * existing_avg
 *              + received_qty * received_cost)
 *             / (total_qty_all_locations + received_qty)
 *
 * Two consequences worth stating, because they are the point of the
 * change:
 *   - A TRANSFER IS COST-NEUTRAL BY DEFINITION. There is only one
 *     average, so moving units between warehouses cannot change it.
 *     confirmGtn no longer has to read and carry a source cost across.
 *   - Every location values at the same unit cost, so the Stock
 *     Valuation report's per-warehouse figures are now slices of one
 *     company-wide valuation rather than independent ones.
 *
 * When stock leaves (issue, transfer, return, adjustment down) the
 * average stays exactly as it was -- units leave at the current
 * weighted average, which is what makes the average "weighted" rather
 * than re-derived.
 *
 * `stock_items.cost_value` is the extended value of everything on hand
 * (total quantity across all warehouses x avg_cost). It is RECOMPUTED
 * from the authoritative per-warehouse quantities after every movement
 * rather than incremented, so it cannot drift out of step with them.
 *
 * Every movement row records the running balance it produced
 * (`qty_after`, `avg_cost_after`, `cost_value_after`) so a later
 * recalculation can be tallied back against history.
 *
 * NEITHER QUANTITY NOR COST MAY GO NEGATIVE. A deduction larger than
 * what is on hand is refused outright (never a partial issue), and a
 * receipt at a negative unit cost is refused at entry -- with no
 * negative quantity and no negative receipt cost, a negative average
 * is unreachable.
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

    /** The item row that carries this company's single average cost for it. */
    private static function itemOrFail(string $companyId, string $stockItemId): StockItem
    {
        $item = StockItem::where('company_id', $companyId)->find($stockItemId);
        if (! $item) {
            throw new InventoryRuleViolation('Stock item not found');
        }

        return $item;
    }

    /** Total quantity on hand for this item across every warehouse. */
    private static function totalQuantity(string $companyId, string $stockItemId): int
    {
        return (int) StockLevel::where('company_id', $companyId)
            ->where('stock_item_id', $stockItemId)
            ->sum('quantity');
    }

    /**
     * Recompute the item's extended value from the authoritative
     * per-warehouse quantities and persist it. Always derived, never
     * incremented, so `cost_value` cannot drift from `stock_levels`.
     */
    private static function revalueItem(StockItem $item, string $companyId, string $stockItemId): void
    {
        $qty = self::totalQuantity($companyId, $stockItemId);
        $item->cost_value = Money::of($qty)
            ->multipliedByMoney(Money::of($item->avg_cost))
            ->quantize(self::TOTAL_SCALE)
            ->toString(self::TOTAL_SCALE);
        $item->save();
    }

    /**
     * The running balance to stamp on a movement row -- the state
     * AFTER it, so the ledger can be tallied back line by line.
     *
     * @return array<string, mixed>
     */
    private static function balanceAfter(StockItem $item, string $companyId, string $stockItemId): array
    {
        $qty = self::totalQuantity($companyId, $stockItemId);

        return [
            'qty_after' => $qty,
            'avg_cost_after' => (string) $item->avg_cost,
            'cost_value_after' => (string) $item->cost_value,
        ];
    }

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
        ?string $movementType = null,
    ): StockMovement {
        // A negative receipt cost is the only way a weighted average
        // could ever turn negative, so it is refused at entry. Checked
        // on the raw input rather than through Money, which has no
        // comparison method -- and the sign of a value is exact in
        // float for every input this accepts.
        if ((float) $unitCost < 0) {
            throw new InventoryRuleViolation('Receipt unit cost cannot be negative');
        }
        $cost = Money::of($unitCost);

        $item = self::itemOrFail($companyId, $stockItemId);
        $level = self::getOrCreateStockLevel($companyId, $stockItemId, $warehouseId);

        // Weighted average across ALL locations, not just this one.
        // Both operands keep full internal precision until the single
        // quantize at the end, so no intermediate is rounded.
        $globalQty = self::totalQuantity($companyId, $stockItemId);
        $existingValue = Money::of($globalQty)->multipliedByMoney(Money::of($item->avg_cost));
        $incomingValue = Money::of($qty)->multipliedByMoney($cost);
        $newGlobalQty = $globalQty + $qty;
        if ($newGlobalQty > 0) {
            $item->avg_cost = $existingValue->plus($incomingValue)
                ->dividedBy($newGlobalQty)
                ->quantize(self::COST_SCALE)
                ->toString(self::COST_SCALE);
        }
        // A zero-or-negative resulting quantity leaves avg_cost exactly
        // as it was: there is no meaningful average over no units, and
        // inventing one would corrupt the next receipt's weighting.
        $level->quantity += $qty;
        $level->save();
        self::revalueItem($item, $companyId, $stockItemId);

        return StockMovement::create([
            'company_id' => $companyId,
            'stock_item_id' => $stockItemId,
            'warehouse_id' => $warehouseId,
            'movement_type' => $movementType ?? StockMovement::TYPE_RECEIVE,
            'quantity' => $qty,
            'unit_cost' => $cost->toString(self::COST_SCALE),
            'total_cost' => $incomingValue->quantize(self::TOTAL_SCALE)->toString(self::TOTAL_SCALE),
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'notes' => $notes,
            'created_by' => $userId,
        ] + self::balanceAfter($item, $companyId, $stockItemId));
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
        $item = self::itemOrFail($companyId, $stockItemId);
        $level = self::getOrCreateStockLevel($companyId, $stockItemId, $warehouseId);
        // Refused outright rather than partially issued, and checked
        // against the quantity AT THIS WAREHOUSE -- stock held at
        // another branch cannot satisfy an issue from this one.
        if ($level->quantity < $qty) {
            throw new InventoryRuleViolation("Insufficient stock: have {$level->quantity}, need {$qty}");
        }

        // Units leave at the item's single weighted average, which the
        // deduction itself never moves.
        $unitCost = Money::of($item->avg_cost);
        $totalCost = Money::of($qty)->multipliedByMoney($unitCost)->quantize(self::TOTAL_SCALE);
        $level->quantity -= $qty;
        $level->save();
        self::revalueItem($item, $companyId, $stockItemId);

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
        ] + self::balanceAfter($item, $companyId, $stockItemId));
    }

    /**
     * Positive adjustment -- add quantity, re-weighting the average
     * with the cost the adjustment carries.
     *
     * CHANGED 2026-09-15 (Dennis): previously an adjustment-up reused
     * the current average and brought no new cost information, on the
     * grounds that it records a count variance rather than a purchase.
     * It now behaves like a receipt, so that opening balances and found
     * stock can be brought in at their real cost. Passing null keeps
     * the old behaviour for a pure count correction: the current
     * average is reused and the weighting is untouched.
     */
    public static function adjustStockIncrease(
        string $companyId,
        string $stockItemId,
        string $warehouseId,
        int $qty,
        string $referenceId,
        ?string $userId = null,
        ?string $notes = null,
        string|int|float|null $unitCostIn = null,
    ): StockMovement {
        $item = self::itemOrFail($companyId, $stockItemId);

        if ($unitCostIn !== null) {
            // Same weighting as a receipt, including the negative guard.
            return self::receiveStock(
                $companyId, $stockItemId, $warehouseId, $qty, $unitCostIn,
                referenceType: 'adj', referenceId: $referenceId,
                userId: $userId, notes: $notes,
                movementType: StockMovement::TYPE_ADJUSTMENT,
            );
        }

        $level = self::getOrCreateStockLevel($companyId, $stockItemId, $warehouseId);
        $level->quantity += $qty;
        $level->save();
        self::revalueItem($item, $companyId, $stockItemId);

        $unitCost = Money::of($item->avg_cost);

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
        ] + self::balanceAfter($item, $companyId, $stockItemId));
    }

    /**
     * The receiving half of a transfer: add quantity at the item's
     * existing average WITHOUT re-weighting it. Deliberately not
     * receiveStock -- a transfer brings no new cost information into
     * the company, so nothing about the valuation may move. Together
     * with its deductStock partner the pair nets to zero value.
     */
    private static function transferIn(
        string $companyId,
        string $stockItemId,
        string $warehouseId,
        int $qty,
        string $referenceId,
        ?string $userId = null,
    ): StockMovement {
        $item = self::itemOrFail($companyId, $stockItemId);
        $level = self::getOrCreateStockLevel($companyId, $stockItemId, $warehouseId);
        $level->quantity += $qty;
        $level->save();
        self::revalueItem($item, $companyId, $stockItemId);

        $unitCost = Money::of($item->avg_cost);

        return StockMovement::create([
            'company_id' => $companyId,
            'stock_item_id' => $stockItemId,
            'warehouse_id' => $warehouseId,
            'movement_type' => StockMovement::TYPE_TRANSFER_IN,
            'quantity' => $qty,
            'unit_cost' => $unitCost->toString(self::COST_SCALE),
            'total_cost' => Money::of($qty)->multipliedByMoney($unitCost)
                ->quantize(self::TOTAL_SCALE)->toString(self::TOTAL_SCALE),
            'reference_type' => 'gtn',
            'reference_id' => $referenceId,
            'created_by' => $userId,
        ] + self::balanceAfter($item, $companyId, $stockItemId));
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
            // A transfer moves QUANTITY BETWEEN LOCATIONS AND NOTHING
            // ELSE (Dennis, 2026-09-15). Since the weighted average is
            // now held once per item across all locations, there is no
            // cost to carry across and nothing a transfer could
            // revalue -- so the destination side deliberately does NOT
            // go through receiveStock, which would re-weight.
            self::deductStock(
                $gtn->company_id, $line->stock_item_id, $gtn->from_warehouse_id,
                $line->quantity, StockMovement::TYPE_TRANSFER_OUT,
                referenceType: 'gtn', referenceId: $gtn->id,
                userId: $userId,
            );
            self::transferIn(
                $gtn->company_id, $line->stock_item_id, $gtn->to_warehouse_id,
                $line->quantity, $gtn->id, $userId,
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

    // ── GIN confirm ─────────────────────────────────────────────────

    /**
     * Confirm a Goods Issue Note: deduct every line from the warehouse
     * at the item's current weighted average cost, and stamp that cost
     * onto the line so the document still reads correctly after the
     * average later moves.
     *
     * Any line the warehouse cannot cover throws, which rolls the whole
     * document back -- an issue is never partially fulfilled, and an
     * on-hand quantity is never driven negative.
     */
    public static function confirmGin(GoodsIssueNote $gin, ?string $userId = null): void
    {
        if ($gin->status !== StockDocumentStatus::DRAFT) {
            throw new InventoryRuleViolation('GIN is not in draft status');
        }
        foreach ($gin->lines as $line) {
            $movement = self::deductStock(
                $gin->company_id, $line->stock_item_id, $gin->warehouse_id,
                $line->quantity, StockMovement::TYPE_ISSUE,
                referenceType: 'gin', referenceId: $gin->id,
                userId: $userId, notes: $line->notes,
            );

            $line->unit_cost = (string) $movement->unit_cost;
            $line->total_cost = (string) $movement->total_cost;
            $line->save();
        }
        $gin->status = StockDocumentStatus::CONFIRMED;
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
        $adjustment->approved_at = Carbon::now();

        foreach ($adjustment->lines as $line) {
            if ($line->quantity_change > 0) {
                self::adjustStockIncrease(
                    $adjustment->company_id, $line->stock_item_id, $adjustment->warehouse_id,
                    $line->quantity_change, $adjustment->id, $approverId, $line->notes,
                    // Null here means a pure count correction: reuse the
                    // current average, move no weighting.
                    unitCostIn: $line->unit_cost,
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
