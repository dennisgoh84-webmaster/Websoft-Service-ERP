<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\StockItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers App\Http\Controllers\Api\GoodsIssueNoteController -- NEW in
 * backend-php (Dennis, 2026-09-15), not a conversion.
 *
 * The rules it has to hold: an issue deducts at the item's weighted
 * average cost and never moves it; it is refused outright when the
 * warehouse holds too little, rather than partially fulfilled; and no
 * on-hand quantity ever goes negative.
 */
class GoodsIssueNoteTest extends TestCase
{
    use RefreshDatabase;

    private const MODULE = 'goods_issue_note';

    private Company $company;

    private StockItem $item;

    private Warehouse $main;

    private Warehouse $branch;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->item = StockItem::factory()->for($this->company)->create(['code' => 'SW-9300']);
        $this->main = Warehouse::factory()->for($this->company)->create(['code' => 'MAIN']);
        $this->branch = Warehouse::factory()->for($this->company)->create(['code' => 'BR01']);
        $this->token = $this->ownerToken($this->company);
    }

    private function enableModule(Company $company, bool $enabled = true): void
    {
        ModuleCatalog::firstOrCreate(['key' => self::MODULE], ['name' => 'Goods Issue Note', 'is_built' => true]);
        CompanyModule::updateOrCreate(
            ['company_id' => $company->id, 'module_key' => self::MODULE],
            ['enabled' => $enabled],
        );
    }

    private function ownerToken(Company $company): string
    {
        $owner = User::factory()->for($company)->create([
            'role' => User::ROLE_OWNER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $this->enableModule($company);

        return $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234'])
            ->json('access_token');
    }

    private function headers(?string $token = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? $this->token)];
    }

    private function stockIn(int $qty, string $unitCost, ?Warehouse $warehouse = null): void
    {
        InventoryService::receiveStock(
            $this->company->id, $this->item->id, ($warehouse ?? $this->main)->id,
            $qty, $unitCost, 'grn', (string) Str::uuid(),
        );
    }

    private function raise(int $qty, ?Warehouse $warehouse = null): array
    {
        return $this->postJson('/api/stock/gin', [
            'warehouse_id' => ($warehouse ?? $this->main)->id,
            'lines' => [['stock_item_id' => $this->item->id, 'quantity' => $qty]],
        ], $this->headers())->assertStatus(201)->json();
    }

    private function qtyAt(Warehouse $warehouse): int
    {
        return (int) (StockLevel::where('stock_item_id', $this->item->id)
            ->where('warehouse_id', $warehouse->id)->value('quantity') ?? 0);
    }

    public function test_confirming_deducts_at_the_average_cost_and_never_moves_it(): void
    {
        // 10 @ 100 then 5 @ 130 = 15 @ 110.0000
        $this->stockIn(10, '100.00');
        $this->stockIn(5, '130.00');

        $gin = $this->raise(4);
        $this->assertSame('GIN-00001', $gin['gin_number']);
        // A draft carries no cost yet -- the average may still move
        // before it is confirmed.
        $this->assertNull($gin['lines'][0]['unit_cost']);

        $confirmed = $this->postJson("/api/stock/gin/{$gin['id']}/confirm", [], $this->headers())
            ->assertOk()->json();

        $this->assertSame('confirmed', $confirmed['status']);
        // The cost that actually applied is stamped on the line.
        $this->assertEqualsWithDelta(110.0, $confirmed['lines'][0]['unit_cost'], 0.0001);
        $this->assertEqualsWithDelta(440.0, $confirmed['lines'][0]['total_cost'], 0.01);

        $this->assertSame(11, $this->qtyAt($this->main));
        $this->assertSame('110.0000', (string) $this->item->fresh()->avg_cost, 'an issue must never move the average');
        $this->assertSame('1210.00', (string) $this->item->fresh()->cost_value);
    }

    public function test_an_issue_larger_than_stock_on_hand_is_refused_whole(): void
    {
        $this->stockIn(3, '100.00');
        $gin = $this->raise(4);

        $this->postJson("/api/stock/gin/{$gin['id']}/confirm", [], $this->headers())
            ->assertStatus(400)
            ->assertJsonPath('detail', 'Insufficient stock: have 3, need 4');

        // Nothing moved and the document stays draft -- never a
        // partial issue, never a negative quantity.
        $this->assertSame(3, $this->qtyAt($this->main));
        $this->assertSame('draft', $this->getJson('/api/stock/gin', $this->headers())->json('0.status'));
        $this->assertSame(0, StockMovement::where('reference_type', 'gin')->count());
    }

    public function test_a_multi_line_issue_rolls_back_entirely_if_any_line_is_short(): void
    {
        $other = StockItem::factory()->for($this->company)->create(['code' => 'SW-9301']);
        $this->stockIn(10, '100.00');
        InventoryService::receiveStock(
            $this->company->id, $other->id, $this->main->id, 1, '50.00', 'grn', (string) Str::uuid(),
        );

        $gin = $this->postJson('/api/stock/gin', [
            'warehouse_id' => $this->main->id,
            'lines' => [
                ['stock_item_id' => $this->item->id, 'quantity' => 2],   // fine
                ['stock_item_id' => $other->id, 'quantity' => 5],        // short
            ],
        ], $this->headers())->assertStatus(201)->json();

        $this->postJson("/api/stock/gin/{$gin['id']}/confirm", [], $this->headers())->assertStatus(400);

        // The first line must NOT have been applied.
        $this->assertSame(10, $this->qtyAt($this->main));
        $this->assertSame(0, StockMovement::where('reference_type', 'gin')->count());
    }

    public function test_stock_at_another_branch_does_not_make_a_shortfall_good(): void
    {
        $this->stockIn(2, '100.00', $this->main);
        $this->stockIn(99, '100.00', $this->branch);

        $gin = $this->raise(3, $this->main);
        $this->postJson("/api/stock/gin/{$gin['id']}/confirm", [], $this->headers())
            ->assertStatus(400)
            ->assertJsonPath('detail', 'Insufficient stock: have 2, need 3');
    }

    public function test_confirming_twice_is_refused(): void
    {
        $this->stockIn(10, '100.00');
        $gin = $this->raise(2);
        $this->postJson("/api/stock/gin/{$gin['id']}/confirm", [], $this->headers())->assertOk();

        $this->postJson("/api/stock/gin/{$gin['id']}/confirm", [], $this->headers())
            ->assertStatus(400)
            ->assertJsonPath('detail', 'GIN is not in draft status');
        $this->assertSame(8, $this->qtyAt($this->main), 'a second confirm must not deduct again');
    }

    public function test_the_movement_records_the_issue_type_and_running_balance(): void
    {
        $this->stockIn(10, '100.00');
        $gin = $this->raise(4);
        $this->postJson("/api/stock/gin/{$gin['id']}/confirm", [], $this->headers())->assertOk();

        $movement = StockMovement::where('reference_type', 'gin')->firstOrFail();
        // The 'issue' movement type existed but nothing ever wrote it
        // until the Goods Issue Note.
        $this->assertSame(StockMovement::TYPE_ISSUE, $movement->movement_type);
        $this->assertSame(-4, $movement->quantity, 'an outward movement is stored negative');
        $this->assertSame(6, $movement->qty_after);
        $this->assertSame('100.0000', (string) $movement->avg_cost_after);
        $this->assertSame('600.00', (string) $movement->cost_value_after);
    }

    public function test_a_gin_needs_at_least_one_line(): void
    {
        $this->postJson('/api/stock/gin', ['warehouse_id' => $this->main->id, 'lines' => []], $this->headers())
            ->assertStatus(422);
    }

    public function test_a_warehouse_from_another_company_is_refused(): void
    {
        $other = Company::factory()->create();
        $theirWarehouse = Warehouse::factory()->for($other)->create();

        $this->postJson('/api/stock/gin', [
            'warehouse_id' => $theirWarehouse->id,
            'lines' => [['stock_item_id' => $this->item->id, 'quantity' => 1]],
        ], $this->headers())->assertStatus(404);
    }

    public function test_another_companys_gin_is_not_found(): void
    {
        $other = Company::factory()->create();
        $otherToken = $this->ownerToken($other);
        $this->stockIn(5, '100.00');
        $gin = $this->raise(1);

        $this->postJson("/api/stock/gin/{$gin['id']}/confirm", [], $this->headers($otherToken))
            ->assertStatus(404);
    }

    public function test_view_level_can_list_but_not_raise_or_confirm(): void
    {
        $this->enableModule($this->company);
        $group = Group::factory()->for($this->company)->create();
        GroupModuleAuthority::create([
            'group_id' => $group->id, 'module_key' => self::MODULE, 'access_level' => GroupModuleAuthority::VIEW,
        ]);
        $user = User::factory()->for($this->company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        UserCompanyAccess::create(['user_id' => $user->id, 'company_id' => $this->company->id, 'group_id' => $group->id]);
        $token = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234'])
            ->json('access_token');

        $this->getJson('/api/stock/gin', $this->headers($token))->assertOk();
        $this->postJson('/api/stock/gin', [
            'warehouse_id' => $this->main->id,
            'lines' => [['stock_item_id' => $this->item->id, 'quantity' => 1]],
        ], $this->headers($token))->assertStatus(403);
    }
}
