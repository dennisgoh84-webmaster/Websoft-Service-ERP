<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\BillingService;
use App\Services\ContractService;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API-level coverage of App\Http\Controllers\Api\LedgerController --
 * the trial balance and per-account transaction ledger halves of
 * backend/app/routers/ledger.py. See
 * docs/php-conversion-plan.md's "after converting each module"
 * checklist. Business-rule arithmetic is covered separately in
 * LedgerServiceTest.php.
 */
class LedgerTest extends TestCase
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

    private function postAnInvoice(Company $company): string
    {
        $owner = User::where('company_id', $company->id)->where('role', User::ROLE_OWNER)->firstOrFail();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $contract = ContractService::createContract(
            companyId: $company->id, customerId: $customer->id, contractedHours: 10,
            contractValueSgd: 1000, startDate: now()->toDateString(), actorUserId: $owner->id,
        );
        BillingService::issueContractAnnualInvoice($contract, $owner->id);

        return Account::where('company_id', $company->id)->where('code', '1100')->firstOrFail()->id;
    }

    public function test_owner_can_view_a_balanced_trial_balance(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $this->postAnInvoice($company);

        $response = $this->getJson('/api/ledger/trial-balance', $this->headers($token));

        $response->assertOk()->assertJson(['is_balanced' => true]);
        $this->assertEqualsWithDelta($response->json('total_debit'), $response->json('total_credit'), 0.01);
        $this->assertGreaterThan(0, $response->json('total_debit'));
    }

    public function test_account_transactions_shows_posted_lines_with_a_running_balance(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $arAccountId = $this->postAnInvoice($company);

        $response = $this->getJson("/api/ledger/transactions/{$arAccountId}", $this->headers($token));

        $response->assertOk()->assertJson(['account_code' => '1100']);
        $this->assertCount(1, $response->json('rows'));
        // No TaxCode is configured for this factory-made company, so
        // GST is 0 (per BillingService's own "never invented" rule)
        // and the invoice is exactly the SGD 1,000 contract value.
        $this->assertEqualsWithDelta(1000.0, $response->json('rows.0.balance_sgd'), 0.01);
        $this->assertEqualsWithDelta(1000.0, $response->json('closing_balance'), 0.01);
    }

    public function test_account_transactions_for_unknown_account_is_404(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $this->getJson('/api/ledger/transactions/00000000-0000-0000-0000-000000000000', $this->headers($token))
            ->assertStatus(404);
    }

    public function test_an_account_from_another_company_is_not_found(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $tokenA = $this->ownerToken($companyA);
        $accountB = Account::where('company_id', $companyB->id)->where('code', '1100')->firstOrFail();

        $this->getJson("/api/ledger/transactions/{$accountB->id}", $this->headers($tokenA))->assertStatus(404);
    }

    public function test_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/ledger/trial-balance', $this->headers($login->json('access_token')))->assertStatus(403);
    }

    public function test_view_only_group_can_view_the_trial_balance(): void
    {
        $company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'finance_accounting'], ['name' => 'Finance / Accounting', 'is_built' => true]);
        CompanyModule::updateOrCreate(['company_id' => $company->id, 'module_key' => 'finance_accounting'], ['enabled' => true]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'finance_accounting', 'access_level' => GroupModuleAuthority::VIEW]);
        $user = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        UserCompanyAccess::create(['user_id' => $user->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/ledger/trial-balance', $this->headers($login->json('access_token')))->assertOk();
    }

    public function test_disabled_module_is_denied_even_with_full_group_authority(): void
    {
        $company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'finance_accounting'], ['name' => 'Finance / Accounting', 'is_built' => true]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'finance_accounting', 'access_level' => GroupModuleAuthority::FULL]);
        // No CompanyModule row at all -- Module Control fails closed.
        $user = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        UserCompanyAccess::create(['user_id' => $user->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/ledger/trial-balance', $this->headers($login->json('access_token')))->assertStatus(403);
    }
}
