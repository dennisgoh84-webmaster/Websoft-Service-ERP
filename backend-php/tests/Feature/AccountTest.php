<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API-level coverage of App\Http\Controllers\Api\AccountController,
 * mirroring backend/app/routers/accounts.py. See
 * docs/php-conversion-plan.md's "after converting each module"
 * checklist.
 */
class AccountTest extends TestCase
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

    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_owner_can_list_the_seeded_chart_and_add_an_account(): void
    {
        $company = Company::factory()->create(); // seeds the minimal chart via CompanyFactory
        $token = $this->ownerToken($company);

        $list = $this->getJson('/api/accounts', $this->headers($token));
        $list->assertOk();
        $this->assertGreaterThanOrEqual(8, count($list->json()));

        $create = $this->postJson('/api/accounts', [
            'code' => '6999', 'name' => 'Miscellaneous expenses', 'account_type' => 'expense',
        ], $this->headers($token));
        $create->assertOk()->assertJson(['code' => '6999', 'is_active' => true]);
    }

    public function test_duplicate_account_code_is_rejected(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $this->postJson('/api/accounts', [
            'code' => '1000', 'name' => 'Duplicate', 'account_type' => 'asset',
        ], $this->headers($token))->assertStatus(409);
    }

    public function test_owner_can_retire_an_account(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $account = Account::where('company_id', $company->id)->where('code', '1000')->firstOrFail();

        $response = $this->patchJson("/api/accounts/{$account->id}", ['is_active' => false], $this->headers($token));

        $response->assertOk()->assertJson(['is_active' => false]);
    }

    public function test_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/accounts', $this->headers($login->json('access_token')))->assertStatus(403);
    }
}
