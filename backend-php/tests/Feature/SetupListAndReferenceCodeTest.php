<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\ReferenceCode;
use App\Models\SetupListItem;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers App\Http\Controllers\Api\SetupListController and
 * App\Http\Controllers\Api\ReferenceCodeController -- converted from
 * backend/app/routers/setup_lists.py and reference_codes.py.
 */
class SetupListAndReferenceCodeTest extends TestCase
{
    use RefreshDatabase;

    private function enableModule(Company $company, string $module, bool $enabled = true): void
    {
        ModuleCatalog::firstOrCreate(['key' => $module], ['name' => $module, 'is_built' => true]);
        CompanyModule::updateOrCreate(
            ['company_id' => $company->id, 'module_key' => $module],
            ['enabled' => $enabled],
        );
    }

    private function ownerToken(Company $company): string
    {
        $owner = User::factory()->for($company)->create([
            'role' => User::ROLE_OWNER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $this->enableModule($company, 'core_administration');
        $this->enableModule($company, 'finance_accounting');
        $login = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234']);

        return $login->json('access_token');
    }

    private function tokenForLevel(Company $company, string $module, string $level): string
    {
        $this->enableModule($company, $module);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => $module, 'access_level' => $level]);
        $user = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        UserCompanyAccess::create(['user_id' => $user->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        return $login->json('access_token');
    }

    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    // ---------------- Setup Lists ----------------

    public function test_setup_lists_are_global_not_company_scoped(): void
    {
        // The opposite of every other module's isolation test, and the
        // point of this one: a country's name does not differ per
        // company, so both companies see the SAME row.
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $tokenA = $this->ownerToken($companyA);
        $tokenB = $this->ownerToken($companyB);

        $this->postJson('/api/setup-lists', [
            'list_type' => SetupListItem::TYPE_COUNTRY, 'code' => 'SG', 'name' => 'Singapore',
        ], $this->headers($tokenA))->assertOk();

        $this->getJson('/api/setup-lists', $this->headers($tokenB))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.code', 'SG');
    }

    public function test_the_same_code_may_exist_under_two_different_list_types(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        // Uniqueness is (list_type, code), not code alone.
        $this->postJson('/api/setup-lists', ['list_type' => SetupListItem::TYPE_COUNTRY, 'code' => 'SG', 'name' => 'Singapore'], $this->headers($token))->assertOk();
        $this->postJson('/api/setup-lists', ['list_type' => SetupListItem::TYPE_CURRENCY, 'code' => 'SG', 'name' => 'Singapore Dollar'], $this->headers($token))->assertOk();

        $this->postJson('/api/setup-lists', ['list_type' => SetupListItem::TYPE_COUNTRY, 'code' => 'SG', 'name' => 'Duplicate'], $this->headers($token))
            ->assertStatus(409)
            ->assertJsonPath('detail', 'country code SG already exists.');
    }

    public function test_setup_list_ordering_and_filtering(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        SetupListItem::create(['list_type' => SetupListItem::TYPE_COUNTRY, 'code' => 'MY', 'name' => 'Malaysia', 'sort_order' => 2]);
        SetupListItem::create(['list_type' => SetupListItem::TYPE_COUNTRY, 'code' => 'SG', 'name' => 'Singapore', 'sort_order' => 1]);
        SetupListItem::create(['list_type' => SetupListItem::TYPE_CURRENCY, 'code' => 'SGD', 'name' => 'Dollar', 'sort_order' => 1]);
        SetupListItem::create(['list_type' => SetupListItem::TYPE_COUNTRY, 'code' => 'XX', 'name' => 'Retired', 'is_active' => false]);

        // Ordered by list_type, then sort_order, then code.
        $this->getJson('/api/setup-lists', $this->headers($token))
            ->assertOk()->assertJsonCount(3)
            ->assertJsonPath('0.code', 'SG')
            ->assertJsonPath('1.code', 'MY')
            ->assertJsonPath('2.code', 'SGD');

        $this->getJson('/api/setup-lists?list_type=country', $this->headers($token))
            ->assertOk()->assertJsonCount(2);

        $this->getJson('/api/setup-lists?include_inactive=true', $this->headers($token))
            ->assertOk()->assertJsonCount(4);
    }

    public function test_a_state_keeps_a_soft_parent_code_reference(): void
    {
        // parent_code is a plain string, deliberately NOT a foreign key:
        // the parent can live in a different list_type.
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $this->postJson('/api/setup-lists', [
            'list_type' => SetupListItem::TYPE_STATE, 'code' => 'JHR', 'name' => 'Johor', 'parent_code' => 'MY',
        ], $this->headers($token))->assertOk()->assertJsonPath('parent_code', 'MY');
    }

    public function test_setup_list_item_cannot_be_moved_between_list_types(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $item = SetupListItem::create(['list_type' => SetupListItem::TYPE_COUNTRY, 'code' => 'SG', 'name' => 'Singapore']);

        // list_type is absent from Python's SetupListItemUpdate.
        $this->patchJson("/api/setup-lists/{$item->id}", [
            'list_type' => SetupListItem::TYPE_CURRENCY, 'name' => 'Renamed',
        ], $this->headers($token))->assertOk()
            ->assertJsonPath('list_type', SetupListItem::TYPE_COUNTRY)
            ->assertJsonPath('name', 'Renamed');
    }

    public function test_setup_list_csv_export_writes_empty_string_for_a_missing_parent(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        SetupListItem::create(['list_type' => SetupListItem::TYPE_COUNTRY, 'code' => 'SG', 'name' => 'Singapore']);

        $csv = $this->get('/api/setup-lists/export.csv', $this->headers($token))->assertOk()->getContent();
        $this->assertStringContainsString('list_type,code,name,parent_code,sort_order,is_active', $csv);
        $this->assertStringContainsString('country,SG,Singapore,,0,True', $csv);
    }

    // ---------------- Reference Codes ----------------

    private function account(Company $company, string $code = '45001', string $name = 'Sales of Software Revenue'): Account
    {
        return Account::create([
            'company_id' => $company->id, 'code' => $code, 'name' => $name,
            'account_type' => Account::TYPE_REVENUE,
        ]);
    }

    public function test_reference_code_list_joins_the_account_code_and_name(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $account = $this->account($company);
        ReferenceCode::create(['company_id' => $company->id, 'account_id' => $account->id, 'code' => 'SLS-IMPL', 'name' => 'Implementation']);

        $this->getJson('/api/reference-codes', $this->headers($token))
            ->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.code', 'SLS-IMPL')
            ->assertJsonPath('0.account_code', '45001')
            ->assertJsonPath('0.account_name', 'Sales of Software Revenue');
    }

    public function test_reference_codes_filter_by_account_and_hide_inactive(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $a = $this->account($company, '45001', 'Software Revenue');
        $b = $this->account($company, '45002', 'Service Revenue');

        ReferenceCode::create(['company_id' => $company->id, 'account_id' => $a->id, 'code' => 'A1', 'name' => 'One']);
        ReferenceCode::create(['company_id' => $company->id, 'account_id' => $b->id, 'code' => 'B1', 'name' => 'Two']);
        ReferenceCode::create(['company_id' => $company->id, 'account_id' => $a->id, 'code' => 'A2', 'name' => 'Retired', 'is_active' => false]);

        $this->getJson("/api/reference-codes?account_id={$a->id}", $this->headers($token))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.code', 'A1');
        $this->getJson('/api/reference-codes?include_inactive=true', $this->headers($token))
            ->assertOk()->assertJsonCount(3);
    }

    public function test_creating_against_another_companys_account_is_404_not_409(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $token = $this->ownerToken($company);
        $theirAccount = $this->account($other);

        // Python checks the account before the duplicate check, so a
        // cross-company account_id is a 404 even if the code also clashes.
        $this->postJson('/api/reference-codes', [
            'account_id' => $theirAccount->id, 'code' => 'X', 'name' => 'Nope',
        ], $this->headers($token))->assertStatus(404)->assertJsonPath('detail', 'Account not found');
    }

    public function test_reference_code_duplicate_is_409(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $account = $this->account($company);
        ReferenceCode::create(['company_id' => $company->id, 'account_id' => $account->id, 'code' => 'SLS', 'name' => 'One']);

        $this->postJson('/api/reference-codes', ['account_id' => $account->id, 'code' => 'SLS', 'name' => 'Two'], $this->headers($token))
            ->assertStatus(409)->assertJsonPath('detail', 'Reference code SLS already exists.');
    }

    public function test_repointing_at_another_companys_account_is_rejected(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $token = $this->ownerToken($company);
        $mine = $this->account($company);
        $theirs = $this->account($other);
        $rc = ReferenceCode::create(['company_id' => $company->id, 'account_id' => $mine->id, 'code' => 'SLS', 'name' => 'One']);

        $this->patchJson("/api/reference-codes/{$rc->id}", ['account_id' => $theirs->id], $this->headers($token))
            ->assertStatus(404);
        $this->assertSame($mine->id, $rc->fresh()->account_id);
    }

    public function test_reference_code_xlsx_export_is_a_real_package(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $account = $this->account($company);
        ReferenceCode::create(['company_id' => $company->id, 'account_id' => $account->id, 'code' => 'SLS-IMPL', 'name' => 'Implementation']);

        $response = $this->get('/api/reference-codes/export.xlsx', $this->headers($token))->assertOk();
        $response->assertHeader('Content-Disposition', 'attachment; filename=reference-codes.xlsx');
        $this->assertStringStartsWith("PK\x03\x04", $response->getContent());
    }

    public function test_reference_codes_are_company_scoped(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $token = $this->ownerToken($company);
        $theirAccount = $this->account($other);
        $theirs = ReferenceCode::create(['company_id' => $other->id, 'account_id' => $theirAccount->id, 'code' => 'THEIRS', 'name' => 'Hidden']);

        $this->getJson('/api/reference-codes', $this->headers($token))->assertOk()->assertJsonCount(0);
        $this->patchJson("/api/reference-codes/{$theirs->id}", ['name' => 'Hijacked'], $this->headers($token))->assertStatus(404);
    }

    public function test_view_level_cannot_edit_either_module(): void
    {
        $company = Company::factory()->create();
        $setupToken = $this->tokenForLevel($company, 'core_administration', GroupModuleAuthority::VIEW);
        $refToken = $this->tokenForLevel($company, 'finance_accounting', GroupModuleAuthority::VIEW);

        $this->getJson('/api/setup-lists', $this->headers($setupToken))->assertOk();
        $this->postJson('/api/setup-lists', ['list_type' => SetupListItem::TYPE_COUNTRY, 'code' => 'X', 'name' => 'No'], $this->headers($setupToken))
            ->assertStatus(403);

        $this->getJson('/api/reference-codes', $this->headers($refToken))->assertOk();
        $this->postJson('/api/reference-codes', ['account_id' => $this->account($company)->id, 'code' => 'X', 'name' => 'No'], $this->headers($refToken))
            ->assertStatus(403);
    }
}
