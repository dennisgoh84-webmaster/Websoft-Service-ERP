<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\CurrencyRate;
use App\Models\GLType;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers App\Http\Controllers\Api\GLTypeController and
 * App\Http\Controllers\Api\CurrencyRateController -- converted from
 * backend/app/routers/gl_types.py and currency_rates.py.
 */
class GLTypeAndCurrencyRateTest extends TestCase
{
    use RefreshDatabase;

    private const MODULE = 'finance_accounting';

    private function enableModule(Company $company, bool $enabled = true): void
    {
        ModuleCatalog::firstOrCreate(['key' => self::MODULE], ['name' => 'Finance / Accounting', 'is_built' => true]);
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
        $login = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234']);

        return $login->json('access_token');
    }

    private function tokenForLevel(Company $company, string $level): string
    {
        $this->enableModule($company);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create([
            'group_id' => $group->id, 'module_key' => self::MODULE, 'access_level' => $level,
        ]);
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

    // ---------------- GL Types ----------------

    public function test_gl_types_list_orders_by_account_type_then_code_and_hides_inactive(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        GLType::create(['company_id' => $company->id, 'code' => 'FA', 'name' => 'Fixed Asset', 'account_type' => Account::TYPE_ASSET]);
        GLType::create(['company_id' => $company->id, 'code' => 'BK', 'name' => 'Bank', 'account_type' => Account::TYPE_ASSET]);
        GLType::create(['company_id' => $company->id, 'code' => 'CL', 'name' => 'Current Liability', 'account_type' => Account::TYPE_LIABILITY]);
        GLType::create(['company_id' => $company->id, 'code' => 'ZZ', 'name' => 'Retired', 'account_type' => Account::TYPE_EXPENSE, 'is_active' => false]);

        $this->getJson('/api/gl-types', $this->headers($token))
            ->assertOk()
            ->assertJsonCount(3)
            // asset < liability alphabetically, and within asset: BK < FA.
            ->assertJsonPath('0.code', 'BK')
            ->assertJsonPath('1.code', 'FA')
            ->assertJsonPath('2.code', 'CL');

        $this->getJson('/api/gl-types?include_inactive=true', $this->headers($token))
            ->assertOk()->assertJsonCount(4);
    }

    public function test_gl_type_duplicate_code_is_409_and_create_cannot_set_is_active(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $this->postJson('/api/gl-types', [
            'code' => 'BK', 'name' => 'Bank', 'account_type' => Account::TYPE_ASSET, 'is_active' => false,
        ], $this->headers($token))->assertOk()->assertJsonPath('is_active', true);

        $this->postJson('/api/gl-types', ['code' => 'BK', 'name' => 'Again', 'account_type' => Account::TYPE_ASSET], $this->headers($token))
            ->assertStatus(409)
            ->assertJsonPath('detail', 'GL Type code BK already exists.');
    }

    public function test_gl_type_account_type_must_be_one_of_the_five(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $this->postJson('/api/gl-types', ['code' => 'X', 'name' => 'Bad', 'account_type' => 'goodwill'], $this->headers($token))
            ->assertStatus(422);
    }

    public function test_another_companys_gl_type_is_not_found(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $token = $this->ownerToken($company);
        $theirs = GLType::create(['company_id' => $other->id, 'code' => 'BK', 'name' => 'Theirs', 'account_type' => Account::TYPE_ASSET]);

        $this->patchJson("/api/gl-types/{$theirs->id}", ['name' => 'Hijacked'], $this->headers($token))->assertStatus(404);
    }

    public function test_accounts_can_now_carry_a_gl_type_id(): void
    {
        // Python's Account has had gl_type_id all along; backend-php's
        // accounts table never carried the column until this migration.
        // AccountOut does not expose it, so this is schema parity only --
        // no API change.
        $company = Company::factory()->create();
        $glType = GLType::create(['company_id' => $company->id, 'code' => 'BK', 'name' => 'Bank', 'account_type' => Account::TYPE_ASSET]);
        $account = Account::create([
            'company_id' => $company->id, 'code' => '1001', 'name' => 'Cash at Bank',
            'account_type' => Account::TYPE_ASSET,
        ]);

        $account->gl_type_id = $glType->id;
        $account->save();

        $this->assertSame($glType->id, $account->fresh()->gl_type_id);
    }

    // ---------------- Currency Rates ----------------

    public function test_currency_rates_list_orders_by_code_then_newest_date_first(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        CurrencyRate::create(['company_id' => $company->id, 'currency_code' => 'USD', 'rate_to_base' => 1.35, 'effective_date' => '2026-01-01']);
        CurrencyRate::create(['company_id' => $company->id, 'currency_code' => 'USD', 'rate_to_base' => 1.34, 'effective_date' => '2026-06-01']);
        CurrencyRate::create(['company_id' => $company->id, 'currency_code' => 'EUR', 'rate_to_base' => 1.46, 'effective_date' => '2026-01-01']);

        $this->getJson('/api/currency-rates', $this->headers($token))
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonPath('0.currency_code', 'EUR')
            ->assertJsonPath('1.currency_code', 'USD')
            // Newest effective_date first within a currency.
            ->assertJsonPath('1.effective_date', '2026-06-01')
            ->assertJsonPath('2.effective_date', '2026-01-01');
    }

    public function test_currency_code_filter_is_case_insensitive(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        CurrencyRate::create(['company_id' => $company->id, 'currency_code' => 'USD', 'rate_to_base' => 1.35, 'effective_date' => '2026-01-01']);

        $this->getJson('/api/currency-rates?currency_code=usd', $this->headers($token))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.currency_code', 'USD');
    }

    public function test_create_uppercases_the_currency_code_and_requires_a_positive_rate(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $this->postJson('/api/currency-rates', ['currency_code' => 'usd', 'rate_to_base' => 1.35, 'effective_date' => '2026-01-01'], $this->headers($token))
            ->assertOk()->assertJsonPath('currency_code', 'USD');

        $this->postJson('/api/currency-rates', ['currency_code' => 'EUR', 'rate_to_base' => 0, 'effective_date' => '2026-01-01'], $this->headers($token))
            ->assertStatus(422);
    }

    public function test_inactive_rates_still_appear_in_the_list(): void
    {
        // Deliberate Python behaviour: unlike GL Types and Tax Types,
        // this list never filters on is_active and has no
        // include_inactive parameter.
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $rate = CurrencyRate::create(['company_id' => $company->id, 'currency_code' => 'USD', 'rate_to_base' => 1.35, 'effective_date' => '2026-01-01']);

        $this->patchJson("/api/currency-rates/{$rate->id}", ['is_active' => false], $this->headers($token))
            ->assertOk()->assertJsonPath('is_active', false);

        $this->getJson('/api/currency-rates', $this->headers($token))->assertOk()->assertJsonCount(1);
    }

    public function test_currency_and_effective_date_are_not_editable(): void
    {
        // Python's CurrencyRateUpdate carries only rate_to_base and
        // is_active -- together with the company, the currency and date
        // are the row's identity.
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $rate = CurrencyRate::create(['company_id' => $company->id, 'currency_code' => 'USD', 'rate_to_base' => 1.35, 'effective_date' => '2026-01-01']);

        $this->patchJson("/api/currency-rates/{$rate->id}", [
            'currency_code' => 'EUR', 'effective_date' => '2026-12-31', 'rate_to_base' => 1.40,
        ], $this->headers($token))->assertOk()
            ->assertJsonPath('currency_code', 'USD')
            ->assertJsonPath('effective_date', '2026-01-01')
            ->assertJsonPath('rate_to_base', 1.4);
    }

    public function test_another_companys_currency_rate_is_not_found(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $token = $this->ownerToken($company);
        $theirs = CurrencyRate::create(['company_id' => $other->id, 'currency_code' => 'USD', 'rate_to_base' => 1.35, 'effective_date' => '2026-01-01']);

        $this->patchJson("/api/currency-rates/{$theirs->id}", ['rate_to_base' => 9.99], $this->headers($token))->assertStatus(404);
    }

    public function test_view_level_can_read_but_not_edit_either_module(): void
    {
        $company = Company::factory()->create();
        $token = $this->tokenForLevel($company, GroupModuleAuthority::VIEW);

        $this->getJson('/api/gl-types', $this->headers($token))->assertOk();
        $this->getJson('/api/currency-rates', $this->headers($token))->assertOk();
        $this->postJson('/api/gl-types', ['code' => 'X', 'name' => 'No', 'account_type' => Account::TYPE_ASSET], $this->headers($token))
            ->assertStatus(403);
        $this->postJson('/api/currency-rates', ['currency_code' => 'USD', 'rate_to_base' => 1, 'effective_date' => '2026-01-01'], $this->headers($token))
            ->assertStatus(403);
    }

    public function test_disabled_module_is_denied_even_with_full_group_authority(): void
    {
        $company = Company::factory()->create();
        $token = $this->tokenForLevel($company, GroupModuleAuthority::FULL);
        $this->enableModule($company, false);

        $this->getJson('/api/gl-types', $this->headers($token))->assertStatus(403);
        $this->getJson('/api/currency-rates', $this->headers($token))->assertStatus(403);
    }
}
