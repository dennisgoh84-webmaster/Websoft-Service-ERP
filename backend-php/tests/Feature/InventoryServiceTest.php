<?php

namespace Tests\Feature;

use App\Exceptions\InventoryRuleViolation;
use App\Models\Company;
use App\Models\GoodsReceiveNote;
use App\Models\GoodsReceiveNoteLine;
use App\Models\GoodsReturnNote;
use App\Models\GoodsReturnNoteLine;
use App\Models\GoodsTransferNote;
use App\Models\GoodsTransferNoteLine;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\StockItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Business-logic coverage of App\Services\InventoryService -- the two
 * confirmed Inventory rules, pinned against worked examples rather
 * than smoke-tested, the way tests/Unit/MoneyTest.php pins SRV-008:
 *
 *   INV-002 -- inventory is valued at weighted average cost.
 *   INV-001 -- a stock adjustment takes effect only once approved.
 *
 * A rounding or ordering regression here fails a test rather than
 * silently mis-valuing stock.
 */
class InventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private StockItem $item;

    private Warehouse $main;

    private Warehouse $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->user = User::factory()->for($this->company)->create();
        $this->item = StockItem::factory()->for($this->company)->create(['code' => 'SW-9300']);
        $this->main = Warehouse::factory()->for($this->company)->create(['code' => 'MAIN']);
        $this->branch = Warehouse::factory()->for($this->company)->create(['code' => 'BR01']);
    }

    private function levelAt(Warehouse $warehouse): ?StockLevel
    {
        return StockLevel::where('stock_item_id', $this->item->id)
            ->where('warehouse_id', $warehouse->id)->first();
    }

    /**
     * The weighted average cost, which since 2026-09-15 is held ONCE
     * PER ITEM across all locations and branches -- not per warehouse.
     */
    private function avgCost(): string
    {
        return (string) $this->item->fresh()->avg_cost;
    }

    /** The extended value of everything on hand for the item. */
    private function costValue(): string
    {
        return (string) $this->item->fresh()->cost_value;
    }

    private function receive(int $qty, string $unitCost, ?Warehouse $warehouse = null): StockMovement
    {
        return InventoryService::receiveStock(
            $this->company->id, $this->item->id, ($warehouse ?? $this->main)->id,
            $qty, $unitCost, 'grn', (string) Str::uuid(), $this->user->id,
        );
    }

    // ── INV-002: weighted average cost ──────────────────────────────

    public function test_first_receipt_sets_the_average_to_its_own_unit_cost(): void
    {
        $movement = $this->receive(10, '100.00');

        $level = $this->levelAt($this->main);
        $this->assertSame(10, $level->quantity);
        $this->assertSame('100.0000', $this->avgCost());
        $this->assertSame('1000.00', $this->costValue());
        // The movement records the extended value at 2dp.
        $this->assertSame('1000.00', (string) $movement->total_cost);
        $this->assertSame(StockMovement::TYPE_RECEIVE, $movement->movement_type);
    }

    /**
     * Worked example (INV-002): 10 units already on hand at $100.00,
     * receive 5 more at $130.00.
     *   (10 x 100 + 5 x 130) / 15 = 1650 / 15 = $110.0000
     */
    public function test_second_receipt_reweights_the_average(): void
    {
        $this->receive(10, '100.00');
        $this->receive(5, '130.00');

        $level = $this->levelAt($this->main);
        $this->assertSame(15, $level->quantity);
        $this->assertSame('110.0000', $this->avgCost());
        $this->assertSame('1650.00', $this->costValue());
    }

    /**
     * Worked example with a recurring decimal, so the 4dp HALF_UP
     * rounding of a stock cost is pinned, not assumed:
     *   15 units at $110.0000 + 3 at $55.50
     *   = (1650 + 166.50) / 18 = 1816.50 / 18 = 100.91666...
     *   -> $100.9167 at Numeric(14, 4).
     */
    public function test_average_rounds_half_up_at_four_decimal_places(): void
    {
        $this->receive(10, '100.00');
        $this->receive(5, '130.00');
        $this->receive(3, '55.50');

        $level = $this->levelAt($this->main);
        $this->assertSame(18, $level->quantity);
        $this->assertSame('100.9167', $this->avgCost());
    }

    public function test_deducting_leaves_the_average_untouched_and_uses_it_as_the_unit_cost(): void
    {
        $this->receive(10, '100.00');
        $this->receive(5, '130.00'); // avg 110.0000

        $movement = InventoryService::deductStock(
            $this->company->id, $this->item->id, $this->main->id,
            4, StockMovement::TYPE_RETURN_OUT, 'grtn', (string) Str::uuid(), $this->user->id,
        );

        $level = $this->levelAt($this->main);
        $this->assertSame(11, $level->quantity);
        $this->assertSame('110.0000', $this->avgCost(), 'a deduction must never re-derive the average');
        $this->assertSame(-4, $movement->quantity, 'an outward movement is stored negative');
        $this->assertSame('110.0000', (string) $movement->unit_cost);
        $this->assertSame('440.00', (string) $movement->total_cost);
    }

    public function test_stock_can_never_go_negative(): void
    {
        $this->receive(3, '100.00');

        $this->expectException(InventoryRuleViolation::class);
        $this->expectExceptionMessage('Insufficient stock: have 3, need 4');

        InventoryService::deductStock(
            $this->company->id, $this->item->id, $this->main->id,
            4, StockMovement::TYPE_ADJUSTMENT, 'adj', (string) Str::uuid(),
        );
    }

    public function test_a_receipt_into_an_empty_location_creates_the_level_row(): void
    {
        $this->assertNull($this->levelAt($this->branch));
        $this->receive(2, '50.00', $this->branch);
        $this->assertSame(2, $this->levelAt($this->branch)->quantity);
    }

    // ── Costing across locations (2026-09-15) ───────────────────────

    /**
     * THE HEADING CHANGE: the average is one figure for the item across
     * every location. A receipt into BR01 re-weights the same average
     * that MAIN's units are valued at, because there is only one.
     *
     *   10 @ 100 into MAIN, then 5 @ 130 into BR01
     *   = (1000 + 650) / 15 = $110.0000 company-wide
     */
    public function test_a_receipt_at_one_branch_reweights_the_average_for_every_branch(): void
    {
        $this->receive(10, '100.00', $this->main);
        $this->receive(5, '130.00', $this->branch);

        $this->assertSame('110.0000', $this->avgCost());
        $this->assertSame('1650.00', $this->costValue(), 'total value spans all locations');
        $this->assertSame(10, $this->levelAt($this->main)->quantity);
        $this->assertSame(5, $this->levelAt($this->branch)->quantity);
    }

    public function test_cost_value_is_quantity_across_all_locations_times_the_average(): void
    {
        $this->receive(10, '100.00', $this->main);
        $this->receive(5, '100.00', $this->branch);

        // 15 units on hand in two places, one average.
        $this->assertSame('100.0000', $this->avgCost());
        $this->assertSame('1500.00', $this->costValue());

        // Issuing from one location reduces the company-wide value.
        InventoryService::deductStock(
            $this->company->id, $this->item->id, $this->main->id,
            4, StockMovement::TYPE_ISSUE, 'gin', (string) Str::uuid(), $this->user->id,
        );
        $this->assertSame('100.0000', $this->avgCost(), 'issuing never moves the average');
        $this->assertSame('1100.00', $this->costValue());
    }

    /**
     * An issue may only draw on what is AT THAT LOCATION -- stock held
     * at another branch does not make a shortfall good.
     */
    public function test_stock_at_another_branch_cannot_satisfy_an_issue(): void
    {
        $this->receive(2, '100.00', $this->main);
        $this->receive(50, '100.00', $this->branch);

        $this->expectException(InventoryRuleViolation::class);
        $this->expectExceptionMessage('Insufficient stock: have 2, need 3');

        InventoryService::deductStock(
            $this->company->id, $this->item->id, $this->main->id,
            3, StockMovement::TYPE_ISSUE, 'gin', (string) Str::uuid(),
        );
    }

    public function test_a_negative_receipt_cost_is_refused(): void
    {
        // With no negative quantity and no negative receipt cost, a
        // negative weighted average is unreachable.
        $this->expectException(InventoryRuleViolation::class);
        $this->expectExceptionMessage('Receipt unit cost cannot be negative');

        $this->receive(5, '-1.00');
    }

    /** Every movement carries the running balance it produced. */
    public function test_each_movement_records_the_balance_it_produced(): void
    {
        $first = $this->receive(10, '100.00');
        $this->assertSame(10, $first->qty_after);
        $this->assertSame('100.0000', (string) $first->avg_cost_after);
        $this->assertSame('1000.00', (string) $first->cost_value_after);

        $second = $this->receive(5, '130.00');
        $this->assertSame(15, $second->qty_after);
        $this->assertSame('110.0000', (string) $second->avg_cost_after);
        $this->assertSame('1650.00', (string) $second->cost_value_after);

        // The history tallies: value after = qty after x average after,
        // which is what makes a later recalculation checkable.
        $this->assertSame(
            round(15 * 110.0, 2),
            round((float) $second->cost_value_after, 2),
        );
    }

    /**
     * An adjustment-up MAY now carry its own cost and re-weight, for
     * opening balances and found stock (Dennis, 2026-09-15).
     *   10 @ 100 on hand, adjust in 5 @ 130 = (1000 + 650) / 15 = 110
     */
    public function test_an_adjustment_with_a_unit_cost_reweights_like_a_receipt(): void
    {
        $this->receive(10, '100.00');
        $adjustment = $this->makeAdjustment(5, StockAdjustment::STATUS_PENDING_APPROVAL);
        $adjustment->lines()->first()->update(['unit_cost' => '130.0000']);
        $adjustment->load('lines');

        InventoryService::approveAdjustment($adjustment, $this->user->id);
        $adjustment->save();

        $this->assertSame(15, $this->levelAt($this->main)->quantity);
        $this->assertSame('110.0000', $this->avgCost());
        // Still recorded as an adjustment, not disguised as a receipt.
        $movement = StockMovement::where('reference_id', $adjustment->id)->firstOrFail();
        $this->assertSame(StockMovement::TYPE_ADJUSTMENT, $movement->movement_type);
        $this->assertSame('adj', $movement->reference_type);
    }

    // ── Document confirms ───────────────────────────────────────────

    public function test_confirming_a_grn_receives_every_line(): void
    {
        $grn = GoodsReceiveNote::create([
            'company_id' => $this->company->id, 'grn_number' => 'GRN-00001',
            'warehouse_id' => $this->main->id, 'created_by' => $this->user->id,
        ]);
        GoodsReceiveNoteLine::create([
            'grn_id' => $grn->id, 'stock_item_id' => $this->item->id,
            'quantity' => 10, 'unit_cost' => '100.0000', 'total_cost' => '1000.00',
        ]);
        $grn->load('lines');

        InventoryService::confirmGrn($grn, $this->user->id);
        $grn->save();

        $this->assertSame(GoodsReceiveNote::STATUS_CONFIRMED, $grn->fresh()->status);
        $this->assertSame(10, $this->levelAt($this->main)->quantity);
        $this->assertSame('100.0000', $this->avgCost());
    }

    public function test_a_grn_cannot_be_confirmed_twice(): void
    {
        $grn = GoodsReceiveNote::create([
            'company_id' => $this->company->id, 'grn_number' => 'GRN-00001',
            'warehouse_id' => $this->main->id, 'status' => GoodsReceiveNote::STATUS_CONFIRMED,
        ]);
        $grn->load('lines');

        $this->expectException(InventoryRuleViolation::class);
        $this->expectExceptionMessage('GRN is not in draft status');
        InventoryService::confirmGrn($grn, $this->user->id);
    }

    /**
     * A transfer's dual-warehouse effect: the source loses the units,
     * the destination gains them, and they arrive carrying the
     * SOURCE's weighted average cost -- a transfer moves stock, it
     * never revalues it.
     */
    public function test_confirming_a_gtn_moves_stock_and_carries_the_source_cost(): void
    {
        $this->receive(10, '100.00');
        $this->receive(5, '130.00'); // MAIN: 15 @ 110.0000

        $gtn = GoodsTransferNote::create([
            'company_id' => $this->company->id, 'gtn_number' => 'GTN-00001',
            'from_warehouse_id' => $this->main->id, 'to_warehouse_id' => $this->branch->id,
            'created_by' => $this->user->id,
        ]);
        GoodsTransferNoteLine::create([
            'gtn_id' => $gtn->id, 'stock_item_id' => $this->item->id, 'quantity' => 6,
        ]);
        $gtn->load('lines');

        InventoryService::confirmGtn($gtn, $this->user->id);
        $gtn->save();

        $this->assertSame(9, $this->levelAt($this->main)->quantity);
        $this->assertSame(6, $this->levelAt($this->branch)->quantity);

        // A TRANSFER MOVES QUANTITY AND NOTHING ELSE: the single
        // company-wide average is untouched, and so is the total value
        // -- 15 units at 110.0000 before and after, just in two places.
        $this->assertSame('110.0000', $this->avgCost(), 'a transfer must never move the average');
        $this->assertSame('1650.00', $this->costValue(), 'a transfer must never change total value');

        // Two movement rows, one per side. The inbound side is a
        // transfer_in, NOT a receive -- a receive would re-weight.
        $this->assertSame(1, StockMovement::where('reference_id', $gtn->id)
            ->where('movement_type', StockMovement::TYPE_TRANSFER_OUT)->count());
        $this->assertSame(1, StockMovement::where('reference_id', $gtn->id)
            ->where('movement_type', StockMovement::TYPE_TRANSFER_IN)
            ->where('warehouse_id', $this->branch->id)->count());
    }

    public function test_confirming_a_grtn_deducts_at_the_average_cost_not_the_line_cost(): void
    {
        $this->receive(10, '100.00'); // avg 100.0000

        $grtn = GoodsReturnNote::create([
            'company_id' => $this->company->id, 'grtn_number' => 'GRTN-00001',
            'warehouse_id' => $this->main->id, 'created_by' => $this->user->id,
        ]);
        // Claiming $120 back from the supplier does NOT change what the
        // units cost us.
        GoodsReturnNoteLine::create([
            'grtn_id' => $grtn->id, 'stock_item_id' => $this->item->id,
            'quantity' => 3, 'unit_cost' => '120.0000', 'total_cost' => '360.00',
        ]);
        $grtn->load('lines');

        InventoryService::confirmGrtn($grtn, $this->user->id);
        $grtn->save();

        $this->assertSame(7, $this->levelAt($this->main)->quantity);
        $this->assertSame('100.0000', $this->avgCost());
        $movement = StockMovement::where('reference_id', $grtn->id)->firstOrFail();
        $this->assertSame('100.0000', (string) $movement->unit_cost);
        $this->assertSame('300.00', (string) $movement->total_cost);
    }

    // ── INV-001: adjustments require approval ───────────────────────

    private function makeAdjustment(int $change, string $status = StockAdjustment::STATUS_DRAFT): StockAdjustment
    {
        $adjustment = StockAdjustment::create([
            'company_id' => $this->company->id, 'adj_number' => 'ADJ-00001',
            'warehouse_id' => $this->main->id, 'reason' => 'Annual count variance',
            'status' => $status, 'created_by' => $this->user->id,
        ]);
        StockAdjustmentLine::create([
            'adjustment_id' => $adjustment->id, 'stock_item_id' => $this->item->id,
            'quantity_change' => $change,
        ]);

        return $adjustment->load('lines');
    }

    public function test_a_draft_adjustment_cannot_be_approved(): void
    {
        $this->receive(10, '100.00');
        $adjustment = $this->makeAdjustment(-2);

        $this->expectException(InventoryRuleViolation::class);
        $this->expectExceptionMessage('Adjustment must be pending approval');
        InventoryService::approveAdjustment($adjustment, $this->user->id);
    }

    public function test_approving_applies_a_negative_adjustment_at_the_current_average(): void
    {
        $this->receive(10, '100.00');
        $this->receive(5, '130.00'); // 15 @ 110.0000
        $adjustment = $this->makeAdjustment(-2, StockAdjustment::STATUS_PENDING_APPROVAL);

        InventoryService::approveAdjustment($adjustment, $this->user->id);
        $adjustment->save();

        $this->assertSame(StockAdjustment::STATUS_APPROVED, $adjustment->fresh()->status);
        $this->assertSame($this->user->id, $adjustment->fresh()->approved_by);
        $this->assertNotNull($adjustment->fresh()->approved_at);
        $this->assertSame(13, $this->levelAt($this->main)->quantity);
        $this->assertSame('110.0000', $this->avgCost());
    }

    /**
     * A positive adjustment WITHOUT its own unit cost is a pure count
     * correction: it adds units at the current average and must not
     * move the weighting. (Supplying a cost re-weights instead -- see
     * the next test.)
     */
    public function test_approving_applies_a_positive_adjustment_without_moving_the_average(): void
    {
        $this->receive(10, '100.00');
        $adjustment = $this->makeAdjustment(4, StockAdjustment::STATUS_PENDING_APPROVAL);

        InventoryService::approveAdjustment($adjustment, $this->user->id);
        $adjustment->save();

        $this->assertSame(14, $this->levelAt($this->main)->quantity);
        $this->assertSame('100.0000', $this->avgCost());
        $movement = StockMovement::where('reference_id', $adjustment->id)->firstOrFail();
        $this->assertSame(4, $movement->quantity);
        $this->assertSame('adj', $movement->reference_type);
        $this->assertSame('400.00', (string) $movement->total_cost);
    }

    public function test_a_zero_line_moves_nothing_and_writes_no_movement(): void
    {
        $this->receive(10, '100.00');
        $adjustment = $this->makeAdjustment(0, StockAdjustment::STATUS_PENDING_APPROVAL);

        InventoryService::approveAdjustment($adjustment, $this->user->id);

        $this->assertSame(10, $this->levelAt($this->main)->quantity);
        $this->assertSame(0, StockMovement::where('reference_id', $adjustment->id)->count());
    }

    public function test_an_adjustment_that_would_overdraw_is_refused_whole(): void
    {
        $this->receive(1, '100.00');
        $adjustment = $this->makeAdjustment(-5, StockAdjustment::STATUS_PENDING_APPROVAL);

        $this->expectException(InventoryRuleViolation::class);
        InventoryService::approveAdjustment($adjustment, $this->user->id);
    }
}
