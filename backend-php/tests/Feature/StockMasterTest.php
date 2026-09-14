<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\StockBrand;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockModel;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Models\Warehouse;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * API-level coverage of the `stock_master` half of the Stock /
 * Inventory module (StockSetupController, WarehouseController,
 * StockItemController) -- RBAC, multi-company scoping and the audit
 * trail, per docs/php-conversion-plan.md's "after converting each
 * module" checklist. Template: CompanyIndividualTest.php.
 */
class StockMasterTest extends TestCase
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

    /** A staff member whose Group holds exactly `$level` on `$moduleKey`, with Module Control enabled. */
    private function staffToken(Company $company, string $moduleKey, string $level, bool $moduleEnabled = true): string
    {
        ModuleCatalog::firstOrCreate(['key' => $moduleKey], ['name' => $moduleKey, 'is_built' => true]);
        if ($moduleEnabled) {
            CompanyModule::firstOrCreate(
                ['company_id' => $company->id, 'module_key' => $moduleKey],
                ['enabled' => true],
            );
        }
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create([
            'group_id' => $group->id, 'module_key' => $moduleKey, 'access_level' => $level,
        ]);
        $staff = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        UserCompanyAccess::create(['user_id' => $staff->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $login = $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234']);

        return $login->json('access_token');
    }

    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    // ── Setup masters ───────────────────────────────────────────────

    public function test_owner_can_create_list_update_and_toggle_a_category(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $create = $this->postJson('/api/stock/categories', ['code' => 'HW', 'name' => 'Hardware'], $this->headers($token));
        $create->assertStatus(201)->assertJson(['code' => 'HW', 'name' => 'Hardware', 'is_active' => true]);
        $id = $create->json('id');

        $this->getJson('/api/stock/categories', $this->headers($token))->assertOk()->assertJsonCount(1);

        $this->patchJson("/api/stock/categories/{$id}", ['code' => 'HW', 'name' => 'Hardware & Parts'], $this->headers($token))
            ->assertOk()->assertJson(['name' => 'Hardware & Parts']);

        // Never deleted -- deactivated in place.
        $this->patchJson("/api/stock/categories/{$id}/toggle", [], $this->headers($token))
            ->assertOk()->assertJson(['is_active' => false]);
        $this->assertDatabaseHas('stock_categories', ['id' => $id]);

        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'stock_category', 'entity_id' => $id, 'action' => 'created',
        ]);
        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'stock_category', 'entity_id' => $id, 'action' => 'deactivated',
        ]);
    }

    public function test_groups_and_usages_follow_the_same_shape(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $group = $this->postJson('/api/stock/groups', ['code' => 'NET', 'name' => 'Networking'], $this->headers($token));
        $group->assertStatus(201)->assertJson(['code' => 'NET']);
        $usage = $this->postJson('/api/stock/usages', ['code' => 'RES', 'name' => 'Resale'], $this->headers($token));
        $usage->assertStatus(201)->assertJson(['code' => 'RES']);

        $this->patchJson("/api/stock/groups/{$group->json('id')}/toggle", [], $this->headers($token))
            ->assertOk()->assertJson(['is_active' => false]);
        $this->patchJson("/api/stock/usages/{$usage->json('id')}/toggle", [], $this->headers($token))
            ->assertOk()->assertJson(['is_active' => false]);
    }

    public function test_a_model_is_scoped_through_its_brand(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $brand = $this->postJson('/api/stock/brands', ['name' => 'Cisco'], $this->headers($token));
        $brand->assertStatus(201);
        $brandId = $brand->json('id');

        $model = $this->postJson('/api/stock/models', ['brand_id' => $brandId, 'name' => 'Catalyst 9300'], $this->headers($token));
        $model->assertStatus(201)->assertJson(['brand_id' => $brandId, 'name' => 'Catalyst 9300']);

        $this->getJson("/api/stock/brands/{$brandId}/models", $this->headers($token))
            ->assertOk()->assertJsonCount(1);
    }

    public function test_a_model_under_another_companys_brand_is_not_found(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $token = $this->ownerToken($companyA);
        $foreignBrand = StockBrand::create(['company_id' => $companyB->id, 'name' => 'HP']);
        $foreignModel = StockModel::create(['brand_id' => $foreignBrand->id, 'name' => 'ProLiant']);

        $this->postJson('/api/stock/models', ['brand_id' => $foreignBrand->id, 'name' => 'X'], $this->headers($token))
            ->assertStatus(404);
        $this->getJson("/api/stock/brands/{$foreignBrand->id}/models", $this->headers($token))->assertStatus(404);
        $this->patchJson("/api/stock/models/{$foreignModel->id}", ['name' => 'Y'], $this->headers($token))
            ->assertStatus(404);
    }

    // ── Warehouses ──────────────────────────────────────────────────

    public function test_owner_can_create_and_update_a_warehouse(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $create = $this->postJson('/api/stock/warehouses', [
            'code' => 'MAIN', 'name' => 'Main Store', 'address' => '1 Ubi Road',
        ], $this->headers($token));
        $create->assertStatus(201)->assertJson(['code' => 'MAIN', 'is_active' => true]);
        $id = $create->json('id');

        $this->patchJson("/api/stock/warehouses/{$id}", ['name' => 'Main Storeroom'], $this->headers($token))
            ->assertOk()->assertJson(['name' => 'Main Storeroom', 'code' => 'MAIN']);

        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'warehouse', 'entity_id' => $id, 'action' => 'created',
        ]);
    }

    /**
     * Regression for the bug this conversion fixed: the Python PATCH
     * body requires `code`/`name` and has no `is_active` field, so
     * WarehousesPage's Activate/Deactivate button could only 422.
     */
    public function test_warehouse_can_be_deactivated_with_is_active_alone(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $warehouse = Warehouse::factory()->for($company)->create();

        $this->patchJson("/api/stock/warehouses/{$warehouse->id}", ['is_active' => false], $this->headers($token))
            ->assertOk()->assertJson(['is_active' => false]);

        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'warehouse', 'entity_id' => $warehouse->id, 'action' => 'deactivated',
        ]);
    }

    public function test_warehouse_from_another_company_is_not_found(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $token = $this->ownerToken($companyA);
        $foreign = Warehouse::factory()->for($companyB)->create();

        $this->patchJson("/api/stock/warehouses/{$foreign->id}", ['name' => 'X'], $this->headers($token))
            ->assertStatus(404);
    }

    // ── Stock items ─────────────────────────────────────────────────

    public function test_owner_can_create_and_read_back_a_stock_item_with_joined_lookup_names(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $category = StockCategory::create(['company_id' => $company->id, 'code' => 'HW', 'name' => 'Hardware']);
        $brand = StockBrand::create(['company_id' => $company->id, 'name' => 'Cisco']);
        $model = StockModel::create(['brand_id' => $brand->id, 'name' => 'Catalyst 9300']);

        $create = $this->postJson('/api/stock/items', [
            'code' => 'SW-9300', 'name' => '48-port switch',
            'unit_of_measure' => 'PCS', 'reorder_level' => 5,
            'category_id' => $category->id, 'brand_id' => $brand->id, 'model_id' => $model->id,
        ], $this->headers($token));

        $create->assertStatus(201)->assertJson([
            'code' => 'SW-9300', 'reorder_level' => 5,
            'category_name' => 'Hardware', 'brand_name' => 'Cisco', 'model_name' => 'Catalyst 9300',
            'attachments' => [],
        ]);

        $this->getJson("/api/stock/items/{$create->json('id')}", $this->headers($token))
            ->assertOk()->assertJson(['usage_name' => null, 'group_name' => null]);
        $this->getJson('/api/stock/items', $this->headers($token))->assertOk()->assertJsonCount(1);
    }

    /**
     * Regression for the same bug as the warehouse case above:
     * StockItemDetailPage's Activate/Deactivate button sends only
     * `is_active`.
     */
    public function test_stock_item_can_be_deactivated_with_is_active_alone(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $item = StockItem::factory()->for($company)->create();

        $this->patchJson("/api/stock/items/{$item->id}", ['is_active' => false], $this->headers($token))
            ->assertOk()->assertJson(['is_active' => false]);

        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'stock_item', 'entity_id' => $item->id, 'action' => 'deactivated',
        ]);
    }

    public function test_a_lookup_from_another_company_is_rejected(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $token = $this->ownerToken($companyA);
        $foreignCategory = StockCategory::create(['company_id' => $companyB->id, 'code' => 'HW', 'name' => 'Hardware']);

        $this->postJson('/api/stock/items', [
            'code' => 'X', 'name' => 'X', 'category_id' => $foreignCategory->id,
        ], $this->headers($token))->assertStatus(404);
    }

    public function test_stock_item_from_another_company_is_not_found(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $token = $this->ownerToken($companyA);
        $foreign = StockItem::factory()->for($companyB)->create();

        $this->getJson("/api/stock/items/{$foreign->id}", $this->headers($token))->assertStatus(404);
    }

    public function test_attachment_upload_download_and_removal(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $item = StockItem::factory()->for($company)->create();

        $upload = $this->post(
            "/api/stock/items/{$item->id}/attachments",
            ['file' => UploadedFile::fake()->create('datasheet.pdf', 12, 'application/pdf')],
            $this->headers($token),
        );
        $upload->assertStatus(201)->assertJson(['filename' => 'datasheet.pdf', 'stock_item_id' => $item->id]);
        $attachmentId = $upload->json('id');

        // It shows up on the item, and the file really is on disk.
        $this->getJson("/api/stock/items/{$item->id}", $this->headers($token))
            ->assertOk()->assertJsonCount(1, 'attachments');
        $this->get("/api/stock/items/{$item->id}/attachments/{$attachmentId}/download", $this->headers($token))
            ->assertOk();

        $this->delete("/api/stock/items/{$item->id}/attachments/{$attachmentId}", [], $this->headers($token))
            ->assertNoContent();
        $this->getJson("/api/stock/items/{$item->id}", $this->headers($token))
            ->assertOk()->assertJsonCount(0, 'attachments');
    }

    // ── Stock levels ────────────────────────────────────────────────

    public function test_levels_start_empty_and_are_read_only(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        StockItem::factory()->for($company)->create();
        Warehouse::factory()->for($company)->create();

        // Nothing has been received yet, so there is no level row --
        // only a movement creates one.
        $this->getJson('/api/stock/levels', $this->headers($token))->assertOk()->assertJsonCount(0);
    }

    // ── RBAC ────────────────────────────────────────────────────────

    public function test_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/stock/items', $this->headers($login->json('access_token')))->assertStatus(403);
    }

    public function test_view_only_group_cannot_write_stock_master(): void
    {
        $company = Company::factory()->create();
        $token = $this->staffToken($company, 'stock_master', GroupModuleAuthority::VIEW);

        $this->getJson('/api/stock/items', $this->headers($token))->assertOk();
        $this->getJson('/api/stock/warehouses', $this->headers($token))->assertOk();
        $this->getJson('/api/stock/levels', $this->headers($token))->assertOk();
        $this->postJson('/api/stock/items', ['code' => 'X', 'name' => 'X'], $this->headers($token))->assertStatus(403);
        $this->postJson('/api/stock/warehouses', ['code' => 'X', 'name' => 'X'], $this->headers($token))->assertStatus(403);
        $this->postJson('/api/stock/categories', ['code' => 'X', 'name' => 'X'], $this->headers($token))->assertStatus(403);
    }

    /**
     * Python gates stock_master writes at FULL, not EDIT -- an EDIT
     * group must still be refused.
     */
    public function test_edit_level_group_cannot_write_stock_master(): void
    {
        $company = Company::factory()->create();
        $token = $this->staffToken($company, 'stock_master', GroupModuleAuthority::EDIT);

        $this->getJson('/api/stock/items', $this->headers($token))->assertOk();
        $this->postJson('/api/stock/items', ['code' => 'X', 'name' => 'X'], $this->headers($token))->assertStatus(403);
    }

    public function test_disabled_module_is_denied_even_with_full_group_authority(): void
    {
        $company = Company::factory()->create();
        // No CompanyModule row at all -- Module Control fails closed.
        $token = $this->staffToken($company, 'stock_master', GroupModuleAuthority::FULL, moduleEnabled: false);

        $this->getJson('/api/stock/items', $this->headers($token))->assertStatus(403);
    }
}
