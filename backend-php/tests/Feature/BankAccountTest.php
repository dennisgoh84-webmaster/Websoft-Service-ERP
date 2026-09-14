<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Company;
use App\Models\User;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API-level coverage of App\Http\Controllers\Api\BankAccountController,
 * mirroring backend/app/routers/bank_accounts.py. See
 * docs/php-conversion-plan.md's "after converting each module"
 * checklist.
 */
class BankAccountTest extends TestCase
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

    public function test_owner_can_create_and_list_bank_accounts(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $create = $this->postJson('/api/bank-accounts', [
            'bank_name' => 'DBS Bank', 'account_name' => 'Webmaster Consultancy Pte Ltd',
            'account_number' => '123-456789-0', 'opening_balance_sgd' => 5000,
        ], $this->headers($token));

        $create->assertOk()->assertJson(['bank_name' => 'DBS Bank', 'current_balance_sgd' => 5000]);

        $this->getJson('/api/bank-accounts', $this->headers($token))->assertOk()->assertJsonCount(1);
    }

    public function test_bank_account_balance_reflects_opening_balance_only_when_no_transactions(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $bank = BankAccount::factory()->for($company)->create(['opening_balance_sgd' => 1000]);

        $response = $this->getJson("/api/bank-accounts/{$bank->id}", $this->headers($token));

        $response->assertOk();
        $this->assertEqualsWithDelta(1000.0, $response->json('current_balance_sgd'), 0.01);
    }

    public function test_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/bank-accounts', $this->headers($login->json('access_token')))->assertStatus(403);
    }
}
