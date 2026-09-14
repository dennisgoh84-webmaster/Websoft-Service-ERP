<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\StockAdjustment;
use App\Models\StockItem;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Models\Warehouse;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API-level coverage of the four stock movement documents (GRN, GTN,
 * GRTN, Stock Adjustment) -- the JSON contract the existing React
 * screens already call, plus the RBAC/multi-company checks from
 * docs/php-conversion-plan.md's "after converting each module" list.
 *
 * Each document has its OWN Module Control key, so each key's gate is
 * exercised independently here (a GRN-only Group must not be able to
 * approve a Stock Adjustment). The INV-001/INV-002 arithmetic itself
 * is pinned in InventoryServiceTest.php.
 */
class StockMovementTest extends TestCase
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

    /** A staff member whose Group holds `$level` on exactly the given module keys. */
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

    private function quantityAt(StockItem $item, Warehouse $warehouse): int
    {
        return (int) (StockLevel::where('stock_item_id', $item->id)
            ->where('warehouse_id', $warehouse->id)->value('quantity') ?? 0);
    }

    // ── Goods Receive Note ──────────────────────────────────────────

    public function test_create_and_confirm_a_grn_moves_stock(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $item = StockItem::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();

        $create = $this->postJson('/api/stock/grn', [
            'warehouse_id' => $warehouse->id,
            'lines' => [['stock_item_id' => $item->id, 'quantity' => 10, 'unit_cost' => 100]],
        ], $this->headers($token));

        $create->assertStatus(201)->assertJson([
            'grn_number' => 'GRN-00001', 'status' => 'draft',
            'lines' => [['quantity' => 10, 'unit_cost' => 100.0, 'total_cost' => 1000.0]],
        ]);
        // Nothing moves until it is confirmed.
        $this->assertSame(0, $this->quantityAt($item, $warehouse));

        $confirm = $this->postJson("/api/stock/grn/{$create->json('id')}/confirm", [], $this->headers($token));
        $confirm->assertOk()->assertJson(['status' => 'confirmed']);
        $this->assertSame(10, $this->quantityAt($item, $warehouse));

        $this->getJson('/api/stock/grn', $this->headers($token))->assertOk()->assertJsonCount(1);
        $this->getJson("/api/stock/grn/{$create->json('id')}", $this->headers($token))->assertOk();
        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'goods_receive_note', 'entity_id' => $create->json('id'), 'action' => 'confirmed',
        ]);
    }

    public function test_confirming_a_grn_twice_returns_400(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $item = StockItem::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();

        $create = $this->postJson('/api/stock/grn', [
            'warehouse_id' => $warehouse->id,
            'lines' => [['stock_item_id' => $item->id, 'quantity' => 1, 'unit_cost' => 5]],
        ], $this->headers($token));
        $id = $create->json('id');

        $this->postJson("/api/stock/grn/{$id}/confirm", [], $this->headers($token))->assertOk();
        $again = $this->postJson("/api/stock/grn/{$id}/confirm", [], $this->headers($token));
        $again->assertStatus(400);
        $this->assertSame('GRN is not in draft status', $again->json('detail'));
    }

    public function test_a_grn_needs_at_least_one_line(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $warehouse = Warehouse::factory()->for($company)->create();

        $this->postJson('/api/stock/grn', [
            'warehouse_id' => $warehouse->id, 'lines' => [],
        ], $this->headers($token))->assertStatus(422);
    }

    public function test_a_warehouse_from_another_company_is_refused(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $token = $this->ownerToken($companyA);
        $item = StockItem::factory()->for($companyA)->create();
        $foreignWarehouse = Warehouse::factory()->for($companyB)->create();

        $this->postJson('/api/stock/grn', [
            'warehouse_id' => $foreignWarehouse->id,
            'lines' => [['stock_item_id' => $item->id, 'quantity' => 1, 'unit_cost' => 1]],
        ], $this->headers($token))->assertStatus(404);
    }

    // ── Goods Transfer Note ─────────────────────────────────────────

    public function test_transfer_moves_stock_between_two_warehouses(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $item = StockItem::factory()->for($company)->create();
        $main = Warehouse::factory()->for($company)->create(['code' => 'MAIN']);
        $branch = Warehouse::factory()->for($company)->create(['code' => 'BR01']);

        $grn = $this->postJson('/api/stock/grn', [
            'warehouse_id' => $main->id,
            'lines' => [['stock_item_id' => $item->id, 'quantity' => 10, 'unit_cost' => 100]],
        ], $this->headers($token));
        $this->postJson("/api/stock/grn/{$grn->json('id')}/confirm", [], $this->headers($token))->assertOk();

        $gtn = $this->postJson('/api/stock/gtn', [
            'from_warehouse_id' => $main->id, 'to_warehouse_id' => $branch->id,
            'lines' => [['stock_item_id' => $item->id, 'quantity' => 4]],
        ], $this->headers($token));
        $gtn->assertStatus(201)->assertJson(['gtn_number' => 'GTN-00001', 'status' => 'draft']);

        $this->postJson("/api/stock/gtn/{$gtn->json('id')}/confirm", [], $this->headers($token))
            ->assertOk()->assertJson(['status' => 'confirmed']);

        $this->assertSame(6, $this->quantityAt($item, $main));
        $this->assertSame(4, $this->quantityAt($item, $branch));
    }

    public function test_transfer_to_the_same_warehouse_is_rejected(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $item = StockItem::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();

        $response = $this->postJson('/api/stock/gtn', [
            'from_warehouse_id' => $warehouse->id, 'to_warehouse_id' => $warehouse->id,
            'lines' => [['stock_item_id' => $item->id, 'quantity' => 1]],
        ], $this->headers($token));

        $response->assertStatus(400);
        $this->assertSame('Source and destination warehouse must be different', $response->json('detail'));
    }

    public function test_transferring_more_than_the_source_holds_is_refused(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $item = StockItem::factory()->for($company)->create();
        $main = Warehouse::factory()->for($company)->create();
        $branch = Warehouse::factory()->for($company)->create();

        $gtn = $this->postJson('/api/stock/gtn', [
            'from_warehouse_id' => $main->id, 'to_warehouse_id' => $branch->id,
            'lines' => [['stock_item_id' => $item->id, 'quantity' => 2]],
        ], $this->headers($token));

        $confirm = $this->postJson("/api/stock/gtn/{$gtn->json('id')}/confirm", [], $this->headers($token));
        $confirm->assertStatus(400);
        $this->assertSame('Insufficient stock: have 0, need 2', $confirm->json('detail'));
        // Rolled back whole: still draft, and no stock created anywhere.
        $this->assertSame('draft', $this->getJson('/api/stock/gtn', $this->headers($token))->json('0.status'));
        $this->assertSame(0, $this->quantityAt($item, $branch));
    }

    // ── Goods Return Note ───────────────────────────────────────────

    public function test_return_note_deducts_stock_on_confirm(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $item = StockItem::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();

        $grn = $this->postJson('/api/stock/grn', [
            'warehouse_id' => $warehouse->id,
            'lines' => [['stock_item_id' => $item->id, 'quantity' => 10, 'unit_cost' => 100]],
        ], $this->headers($token));
        $this->postJson("/api/stock/grn/{$grn->json('id')}/confirm", [], $this->headers($token));

        $grtn = $this->postJson('/api/stock/grtn', [
            'warehouse_id' => $warehouse->id, 'reason' => 'Faulty on arrival',
            'lines' => [['stock_item_id' => $item->id, 'quantity' => 3, 'unit_cost' => 100]],
        ], $this->headers($token));
        $grtn->assertStatus(201)->assertJson(['grtn_number' => 'GRTN-00001', 'reason' => 'Faulty on arrival']);

        $this->postJson("/api/stock/grtn/{$grtn->json('id')}/confirm", [], $this->headers($token))
            ->assertOk()->assertJson(['status' => 'confirmed']);
        $this->assertSame(7, $this->quantityAt($item, $warehouse));
    }

    public function test_a_return_line_unit_cost_defaults_to_zero(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $item = StockItem::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();

        $grtn = $this->postJson('/api/stock/grtn', [
            'warehouse_id' => $warehouse->id,
            'lines' => [['stock_item_id' => $item->id, 'quantity' => 1]],
        ], $this->headers($token));

        $grtn->assertStatus(201)->assertJson(['lines' => [['unit_cost' => 0.0, 'total_cost' => 0.0]]]);
    }

    // ── Stock Adjustment (INV-001) ──────────────────────────────────

    public function test_the_full_inv001_approval_path(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $item = StockItem::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();

        $grn = $this->postJson('/api/stock/grn', [
            'warehouse_id' => $warehouse->id,
            'lines' => [['stock_item_id' => $item->id, 'quantity' => 10, 'unit_cost' => 100]],
        ], $this->headers($token));
        $this->postJson("/api/stock/grn/{$grn->json('id')}/confirm", [], $this->headers($token));

        $adjustment = $this->postJson('/api/stock/adjustments', [
            'warehouse_id' => $warehouse->id, 'reason' => 'Damaged in storage',
            'lines' => [['stock_item_id' => $item->id, 'quantity_change' => -2]],
        ], $this->headers($token));
        $adjustment->assertStatus(201)->assertJson(['adj_number' => 'ADJ-00001', 'status' => 'draft']);
        $id = $adjustment->json('id');

        // Draft changes nothing.
        $this->assertSame(10, $this->quantityAt($item, $warehouse));

        $this->postJson("/api/stock/adjustments/{$id}/submit", [], $this->headers($token))
            ->assertOk()->assertJson(['status' => 'pending_approval']);
        // Still nothing -- INV-001: only approval makes it take effect.
        $this->assertSame(10, $this->quantityAt($item, $warehouse));

        $approve = $this->postJson("/api/stock/adjustments/{$id}/approve", [], $this->headers($token));
        $approve->assertOk()->assertJson(['status' => 'approved']);
        $this->assertNotNull($approve->json('approved_by'));
        $this->assertNotNull($approve->json('approved_at'));
        $this->assertSame(8, $this->quantityAt($item, $warehouse));

        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'stock_adjustment', 'entity_id' => $id, 'action' => 'approved',
        ]);
    }

    public function test_a_draft_adjustment_cannot_be_approved(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $item = StockItem::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();

        $adjustment = $this->postJson('/api/stock/adjustments', [
            'warehouse_id' => $warehouse->id,
            'lines' => [['stock_item_id' => $item->id, 'quantity_change' => 5]],
        ], $this->headers($token));

        $response = $this->postJson("/api/stock/adjustments/{$adjustment->json('id')}/approve", [], $this->headers($token));
        $response->assertStatus(400);
        $this->assertSame('Adjustment must be pending approval', $response->json('detail'));
    }

    public function test_a_rejected_adjustment_never_moves_stock_and_is_kept(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $item = StockItem::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();

        $adjustment = $this->postJson('/api/stock/adjustments', [
            'warehouse_id' => $warehouse->id,
            'lines' => [['stock_item_id' => $item->id, 'quantity_change' => 5]],
        ], $this->headers($token));
        $id = $adjustment->json('id');

        $this->postJson("/api/stock/adjustments/{$id}/submit", [], $this->headers($token))->assertOk();
        $this->postJson("/api/stock/adjustments/{$id}/reject", [], $this->headers($token))
            ->assertOk()->assertJson(['status' => 'rejected']);

        $this->assertSame(0, $this->quantityAt($item, $warehouse));
        $this->assertDatabaseHas('stock_adjustments', ['id' => $id, 'status' => StockAdjustment::STATUS_REJECTED]);
    }

    public function test_adjustment_from_another_company_is_not_found(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $token = $this->ownerToken($companyA);
        $foreign = StockAdjustment::create([
            'company_id' => $companyB->id, 'adj_number' => 'ADJ-00001',
            'warehouse_id' => Warehouse::factory()->for($companyB)->create()->id,
        ]);

        $this->postJson("/api/stock/adjustments/{$foreign->id}/submit", [], $this->headers($token))
            ->assertStatus(404);
    }

    // ── RBAC: each of the four keys gates independently ─────────────

    public function test_user_with_no_group_is_denied_every_document(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);
        $headers = $this->headers($login->json('access_token'));

        $this->getJson('/api/stock/grn', $headers)->assertStatus(403);
        $this->getJson('/api/stock/gtn', $headers)->assertStatus(403);
        $this->getJson('/api/stock/grtn', $headers)->assertStatus(403);
        $this->getJson('/api/stock/adjustments', $headers)->assertStatus(403);
    }

    public function test_view_only_group_can_read_but_not_write_each_document(): void
    {
        $company = Company::factory()->create();
        $token = $this->staffToken($company, [
            'goods_receive_note' => GroupModuleAuthority::VIEW,
            'goods_transfer_note' => GroupModuleAuthority::VIEW,
            'goods_return_note' => GroupModuleAuthority::VIEW,
            'stock_adjustment' => GroupModuleAuthority::VIEW,
        ]);
        $headers = $this->headers($token);

        $this->getJson('/api/stock/grn', $headers)->assertOk();
        $this->getJson('/api/stock/gtn', $headers)->assertOk();
        $this->getJson('/api/stock/grtn', $headers)->assertOk();
        $this->getJson('/api/stock/adjustments', $headers)->assertOk();

        $this->postJson('/api/stock/grn', [], $headers)->assertStatus(403);
        $this->postJson('/api/stock/gtn', [], $headers)->assertStatus(403);
        $this->postJson('/api/stock/grtn', [], $headers)->assertStatus(403);
        $this->postJson('/api/stock/adjustments', [], $headers)->assertStatus(403);
    }

    /**
     * The point of six separate keys: receiving rights must not carry
     * transfer, return or adjustment-approval rights with them.
     */
    public function test_a_grn_only_group_cannot_touch_the_other_documents(): void
    {
        $company = Company::factory()->create();
        $token = $this->staffToken($company, ['goods_receive_note' => GroupModuleAuthority::FULL]);
        $headers = $this->headers($token);
        $item = StockItem::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();

        $this->postJson('/api/stock/grn', [
            'warehouse_id' => $warehouse->id,
            'lines' => [['stock_item_id' => $item->id, 'quantity' => 1, 'unit_cost' => 1]],
        ], $headers)->assertStatus(201);

        $this->getJson('/api/stock/gtn', $headers)->assertStatus(403);
        $this->getJson('/api/stock/grtn', $headers)->assertStatus(403);
        $this->getJson('/api/stock/adjustments', $headers)->assertStatus(403);
    }

    public function test_disabled_module_is_denied_even_with_full_group_authority(): void
    {
        $company = Company::factory()->create();
        // Full authority on every stock document key, but no
        // CompanyModule row anywhere -- Module Control fails closed.
        $token = $this->staffToken($company, [
            'goods_receive_note' => GroupModuleAuthority::FULL,
            'stock_adjustment' => GroupModuleAuthority::FULL,
        ], moduleEnabled: false);
        $headers = $this->headers($token);

        $this->getJson('/api/stock/grn', $headers)->assertStatus(403);
        $this->getJson('/api/stock/adjustments', $headers)->assertStatus(403);
    }
}
