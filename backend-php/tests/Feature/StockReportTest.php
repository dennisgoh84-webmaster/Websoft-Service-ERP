<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * API-level coverage of App\Http\Controllers\Api\StockReportController
 * -- the movements journal, the INV-002 valuation report and the
 * reorder report, all gated by the `stock_operation_reports` module
 * key (its own key: a manager can read the stock reports without any
 * rights to move stock).
 *
 * The valuation figures here are the arithmetic end of the INV-002
 * chain that InventoryServiceTest pins at the service level -- they
 * are asserted as real numbers, not just "a report came back".
 */
class StockReportTest extends TestCase
{
    use RefreshDatabase;

    private function ownerToken(Company $company): string
    {
        $owner = User::factory()->for($company)->create([
            'role' => User::ROLE_OWNER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $login = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234']);

        return $login->json('access_token');
    }

    private function staffToken(Company $company, array $keyLevels, bool $moduleEnabled = true): string
    {
        $group = Group::factory()->for($company)->create();
        foreach ($keyLevels as $key => $level) {
            ModuleCatalog::firstOrCreate(['key' => $key], ['name' => $key, 'is_built' => true]);
            if ($moduleEnabled) {
                CompanyModule::firstOrCreate(['company_id' => $company->id, 'module_key' => $key], ['enabled' => true]);
            }
            GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => $key, 'access_level' => $level]);
        }
        $staff = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        UserCompanyAccess::create(['user_id' => $staff->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $login = $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234']);

        return $login->json('access_token');
    }

    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    private function receive(Company $company, StockItem $item, Warehouse $warehouse, int $qty, string $cost): void
    {
        InventoryService::receiveStock(
            $company->id, $item->id, $warehouse->id, $qty, $cost, 'grn', (string) Str::uuid(),
        );
    }

    /**
     * Worked example end to end: 10 @ $100 then 5 @ $130 into MAIN
     * gives 15 @ $110.0000; transferring 6 to BR01 leaves MAIN with
     * 9 @ $110.0000 (= $990.00) and gives BR01 6 @ $110.0000
     * (= $660.00), for a total stock value of $1,650.00 -- the same
     * $1,650 that went in, because a transfer moves stock without
     * revaluing it.
     */
    public function test_valuation_report_totals_quantity_times_average_cost(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $item = StockItem::factory()->for($company)->create(['code' => 'SW-9300', 'name' => '48-port switch']);
        $main = Warehouse::factory()->for($company)->create(['code' => 'MAIN']);
        $branch = Warehouse::factory()->for($company)->create(['code' => 'BR01']);

        $this->receive($company, $item, $main, 10, '100.00');
        $this->receive($company, $item, $main, 5, '130.00');
        InventoryService::deductStock(
            $company->id, $item->id, $main->id, 6, 'transfer_out', 'gtn', (string) Str::uuid(),
        );
        $this->receive($company, $item, $branch, 6, '110.0000');

        $report = $this->getJson('/api/stock/reports/valuation', $this->headers($token));
        $report->assertOk()->assertJson([
            'items' => [
                ['warehouse_code' => 'BR01', 'quantity' => 6, 'avg_cost' => 110.0, 'total_value' => 660.0],
                ['warehouse_code' => 'MAIN', 'quantity' => 9, 'avg_cost' => 110.0, 'total_value' => 990.0],
            ],
            'total_value' => 1650.0,
        ]);
    }

    public function test_valuation_report_can_be_filtered_to_one_warehouse(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $item = StockItem::factory()->for($company)->create();
        $main = Warehouse::factory()->for($company)->create(['code' => 'MAIN']);
        $branch = Warehouse::factory()->for($company)->create(['code' => 'BR01']);
        $this->receive($company, $item, $main, 4, '25.00');
        $this->receive($company, $item, $branch, 2, '25.00');

        $report = $this->getJson("/api/stock/reports/valuation?warehouse_id={$main->id}", $this->headers($token));
        $report->assertOk()->assertJsonCount(1, 'items')->assertJson(['total_value' => 100.0]);
    }

    public function test_a_zero_quantity_location_is_not_valued(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $item = StockItem::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $this->receive($company, $item, $warehouse, 3, '10.00');
        InventoryService::deductStock(
            $company->id, $item->id, $warehouse->id, 3, 'adjustment', 'adj', (string) Str::uuid(),
        );

        $this->getJson('/api/stock/reports/valuation', $this->headers($token))
            ->assertOk()->assertJsonCount(0, 'items')->assertJson(['total_value' => 0.0]);
    }

    public function test_another_companys_stock_is_never_valued(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $token = $this->ownerToken($companyA);
        $foreignItem = StockItem::factory()->for($companyB)->create();
        $foreignWarehouse = Warehouse::factory()->for($companyB)->create();
        $this->receive($companyB, $foreignItem, $foreignWarehouse, 5, '99.00');

        $this->getJson('/api/stock/reports/valuation', $this->headers($token))
            ->assertOk()->assertJsonCount(0, 'items')->assertJson(['total_value' => 0.0]);
    }

    public function test_reorder_report_lists_items_at_or_below_their_level(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $warehouse = Warehouse::factory()->for($company)->create();

        $low = StockItem::factory()->for($company)->create(['code' => 'AAA', 'reorder_level' => 5, 'unit_of_measure' => 'PCS']);
        $exactlyAt = StockItem::factory()->for($company)->create(['code' => 'BBB', 'reorder_level' => 4]);
        $healthy = StockItem::factory()->for($company)->create(['code' => 'CCC', 'reorder_level' => 2]);
        // No reorder level set -- has not opted in, never reported.
        StockItem::factory()->for($company)->create(['code' => 'DDD', 'reorder_level' => 0]);

        $this->receive($company, $low, $warehouse, 2, '10.00');
        $this->receive($company, $exactlyAt, $warehouse, 4, '10.00');
        $this->receive($company, $healthy, $warehouse, 9, '10.00');

        $report = $this->getJson('/api/stock/reports/reorder', $this->headers($token));
        $report->assertOk()->assertJsonCount(2);
        $report->assertJson([
            ['item_code' => 'AAA', 'reorder_level' => 5, 'current_stock' => 2, 'shortfall' => 3, 'unit_of_measure' => 'PCS'],
            // At the level counts as needing a reorder ("at or below").
            ['item_code' => 'BBB', 'reorder_level' => 4, 'current_stock' => 4, 'shortfall' => 0],
        ]);
    }

    public function test_reorder_report_sums_stock_across_every_warehouse(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $item = StockItem::factory()->for($company)->create(['reorder_level' => 6]);
        $main = Warehouse::factory()->for($company)->create();
        $branch = Warehouse::factory()->for($company)->create();

        $this->receive($company, $item, $main, 4, '10.00');
        $this->receive($company, $item, $branch, 3, '10.00');

        // 4 + 3 = 7, above the level of 6 -- not a reorder.
        $this->getJson('/api/stock/reports/reorder', $this->headers($token))->assertOk()->assertJsonCount(0);
    }

    public function test_movements_journal_is_newest_first_and_filterable(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $itemA = StockItem::factory()->for($company)->create();
        $itemB = StockItem::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();

        $this->receive($company, $itemA, $warehouse, 10, '100.00');
        $this->receive($company, $itemB, $warehouse, 5, '20.00');

        $all = $this->getJson('/api/stock/movements', $this->headers($token));
        $all->assertOk()->assertJsonCount(2);

        $filtered = $this->getJson("/api/stock/movements?stock_item_id={$itemA->id}", $this->headers($token));
        $filtered->assertOk()->assertJsonCount(1)->assertJson([
            ['movement_type' => 'receive', 'quantity' => 10, 'unit_cost' => 100.0, 'total_cost' => 1000.0, 'reference_type' => 'grn'],
        ]);

        $this->getJson('/api/stock/movements?limit=1', $this->headers($token))->assertOk()->assertJsonCount(1);
    }

    /**
     * Regression for the bug the live verification walk caught:
     * Laravel's `timestampTz()` defaults to WHOLE-SECOND precision, so
     * movements written in the same second came back in an arbitrary
     * order and the journal could show a transfer's receipt above the
     * receipt that funded it. `stock_movements.created_at` is
     * timestamptz(6), matching the Python column.
     */
    public function test_movements_written_within_the_same_second_still_order_correctly(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $item = StockItem::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();

        // Written straight through the query builder so the exact
        // sub-second values reach the column: Eloquent's default date
        // format would itself truncate them before the DB ever saw
        // them. In production the values come from the column's own
        // CURRENT_TIMESTAMP default, which does carry microseconds.
        foreach ([['100000', 10], ['900000', 3]] as [$micros, $qty]) {
            DB::table('stock_movements')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'stock_item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'movement_type' => StockMovement::TYPE_RECEIVE,
                'quantity' => $qty,
                'unit_cost' => '1.0000',
                'total_cost' => '1.00',
                'created_at' => "2026-09-14 10:00:00.{$micros}+00",
            ]);
        }

        $journal = $this->getJson('/api/stock/movements', $this->headers($token));
        $journal->assertOk()->assertJsonCount(2);
        // The later fraction-of-a-second must come first.
        $this->assertSame(3, $journal->json('0.quantity'));
        $this->assertSame(10, $journal->json('1.quantity'));
    }

    // ── RBAC ────────────────────────────────────────────────────────

    public function test_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);
        $headers = $this->headers($login->json('access_token'));

        $this->getJson('/api/stock/movements', $headers)->assertStatus(403);
        $this->getJson('/api/stock/reports/valuation', $headers)->assertStatus(403);
        $this->getJson('/api/stock/reports/reorder', $headers)->assertStatus(403);
    }

    /**
     * The reports have their own key: full rights over the Stock
     * Master do not grant them, and vice versa.
     */
    public function test_stock_master_authority_does_not_grant_the_reports(): void
    {
        $company = Company::factory()->create();
        $token = $this->staffToken($company, ['stock_master' => GroupModuleAuthority::FULL]);
        $headers = $this->headers($token);

        $this->getJson('/api/stock/items', $headers)->assertOk();
        $this->getJson('/api/stock/movements', $headers)->assertStatus(403);
        $this->getJson('/api/stock/reports/valuation', $headers)->assertStatus(403);
    }

    public function test_view_only_on_the_reports_key_is_enough_to_read_them(): void
    {
        $company = Company::factory()->create();
        $token = $this->staffToken($company, ['stock_operation_reports' => GroupModuleAuthority::VIEW]);
        $headers = $this->headers($token);

        $this->getJson('/api/stock/movements', $headers)->assertOk();
        $this->getJson('/api/stock/reports/valuation', $headers)->assertOk();
        $this->getJson('/api/stock/reports/reorder', $headers)->assertOk();
        // ...but it grants nothing anywhere else in the module.
        $this->getJson('/api/stock/items', $headers)->assertStatus(403);
    }

    public function test_disabled_module_is_denied_even_with_full_group_authority(): void
    {
        $company = Company::factory()->create();
        $token = $this->staffToken($company, ['stock_operation_reports' => GroupModuleAuthority::FULL], moduleEnabled: false);

        $this->getJson('/api/stock/reports/valuation', $this->headers($token))->assertStatus(403);
    }

    public function test_stock_reports_can_cover_several_of_the_users_companies(): void
    {
        $companyA = Company::factory()->create(['code' => 'C001', 'name' => 'Alpha']);
        $companyB = Company::factory()->create(['code' => 'C002', 'name' => 'Beta']);
        $token = $this->ownerToken($companyA);
        foreach ([[$companyA, 'A-1', '10.00'], [$companyB, 'B-1', '20.00']] as [$company, $code, $cost]) {
            $item = StockItem::factory()->for($company)->create(['code' => $code, 'reorder_level' => 50]);
            $this->receive($company, $item, Warehouse::factory()->for($company)->create(['code' => "WH-{$code}"]), 5, $cost);
        }
        $both = "company_ids={$companyA->id},{$companyB->id}";

        // Nothing sent = the signed-in company only.
        $this->getJson('/api/stock/reports/valuation', $this->headers($token))->assertOk()->assertJsonCount(1, 'items');

        $valuation = $this->getJson("/api/stock/reports/valuation?{$both}", $this->headers($token))->assertOk()->assertJsonCount(2, 'items');
        $this->assertEquals(150, $valuation->json('total_value'));
        $this->assertSame(['C001 Alpha', 'C002 Beta'], array_column($valuation->json('items'), 'company_name'));

        $reorder = $this->getJson("/api/stock/reports/reorder?{$both}", $this->headers($token))->assertOk()->assertJsonCount(2);
        $this->assertSame(['A-1', 'B-1'], array_column($reorder->json(), 'item_code'));

        $movements = $this->getJson("/api/stock/movements?{$both}", $this->headers($token))->assertOk()->assertJsonCount(2);
        $this->assertEqualsCanonicalizing(['A-1', 'B-1'], array_column($movements->json(), 'item_code'));

        $options = $this->getJson("/api/stock/reports/filter-options?{$both}", $this->headers($token))->assertOk();
        $this->assertCount(2, $options->json('items'));
        $this->assertStringEndsWith('(C002)', $options->json('warehouses.1.name'));
    }

    public function test_stock_reports_refuse_a_company_the_user_cannot_switch_to(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $token = $this->staffToken($company, ['stock_operation_reports' => GroupModuleAuthority::VIEW]);

        $this->getJson("/api/stock/reports/valuation?company_ids={$company->id}", $this->headers($token))->assertOk();
        $this->getJson("/api/stock/reports/valuation?company_ids={$other->id}", $this->headers($token))->assertStatus(403);
    }
}
