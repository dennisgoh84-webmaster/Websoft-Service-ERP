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
        $this->assertSame('100.0000', (string) $level->avg_cost);
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
        $this->assertSame('110.0000', (string) $level->avg_cost);
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
        $this->assertSame('100.9167', (string) $level->avg_cost);
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
        $this->assertSame('110.0000', (string) $level->avg_cost, 'a deduction must never re-derive the average');
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
        $this->assertSame('100.0000', (string) $this->levelAt($this->main)->avg_cost);
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
        $this->assertSame('110.0000', (string) $this->levelAt($this->main)->avg_cost);
        $this->assertSame(6, $this->levelAt($this->branch)->quantity);
        $this->assertSame('110.0000', (string) $this->levelAt($this->branch)->avg_cost);

        // Two movement rows, one per side.
        $this->assertSame(1, StockMovement::where('reference_id', $gtn->id)
            ->where('movement_type', StockMovement::TYPE_TRANSFER_OUT)->count());
        $this->assertSame(1, StockMovement::where('reference_id', $gtn->id)
            ->where('movement_type', StockMovement::TYPE_RECEIVE)
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
        $this->assertSame('100.0000', (string) $this->levelAt($this->main)->avg_cost);
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
        $this->assertSame('110.0000', (string) $this->levelAt($this->main)->avg_cost);
    }

    /**
     * A positive adjustment adds units at the CURRENT average -- it
     * carries no new cost information (it records a count variance,
     * not a purchase), so unlike a receipt it must not move the
     * average.
     */
    public function test_approving_applies_a_positive_adjustment_without_moving_the_average(): void
    {
        $this->receive(10, '100.00');
        $adjustment = $this->makeAdjustment(4, StockAdjustment::STATUS_PENDING_APPROVAL);

        InventoryService::approveAdjustment($adjustment, $this->user->id);
        $adjustment->save();

        $this->assertSame(14, $this->levelAt($this->main)->quantity);
        $this->assertSame('100.0000', (string) $this->levelAt($this->main)->avg_cost);
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
