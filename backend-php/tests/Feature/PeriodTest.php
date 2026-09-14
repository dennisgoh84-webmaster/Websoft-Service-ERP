<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
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
use App\Services\Periods;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * API-level coverage of App\Http\Controllers\Api\PeriodController,
 * mirroring backend/app/routers/periods.py. See
 * docs/php-conversion-plan.md's "after converting each module"
 * checklist. Business-rule arithmetic is covered separately in
 * PeriodsServiceTest.php.
 */
class PeriodTest extends TestCase
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

    /** A non-owner with a FULL-authority Group on finance_accounting. */
    private function fullGroupToken(Company $company): string
    {
        ModuleCatalog::firstOrCreate(['key' => 'finance_accounting'], ['name' => 'Finance / Accounting', 'is_built' => true]);
        CompanyModule::updateOrCreate(['company_id' => $company->id, 'module_key' => 'finance_accounting'], ['enabled' => true]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'finance_accounting', 'access_level' => GroupModuleAuthority::FULL]);
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

    public function test_owner_can_create_list_toggle_close_and_reopen_a_period(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $create = $this->postJson('/api/accounting-periods', [
            'fiscal_year' => 2026, 'name' => 'September 2026', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
        ], $this->headers($token));
        $create->assertOk()->assertJson(['name' => 'September 2026', 'status' => 'open']);
        $expectedLocks = array_sum(array_map('count', Periods::VALID_DOC_OPERATIONS));
        $this->assertCount($expectedLocks, $create->json('locks'));
        $periodId = $create->json('id');

        $list = $this->getJson('/api/accounting-periods', $this->headers($token));
        $list->assertOk()->assertJsonCount(1);

        $toggle = $this->postJson("/api/accounting-periods/{$periodId}/toggle-lock", [
            'doc_type' => 'sales_invoice', 'operation' => 'gl', 'locked' => true,
        ], $this->headers($token));
        $toggle->assertOk();
        $glLock = collect($toggle->json('locks'))->first(fn ($l) => $l['doc_type'] === 'sales_invoice' && $l['operation'] === 'gl');
        $this->assertTrue($glLock['is_locked']);
        $this->assertSame('open', $toggle->json('status')); // partial lock -> still open

        $close = $this->postJson("/api/accounting-periods/{$periodId}/close", [], $this->headers($token));
        $close->assertOk()->assertJson(['status' => 'closed']);

        $reopen = $this->postJson("/api/accounting-periods/{$periodId}/reopen", [], $this->headers($token));
        $reopen->assertOk()->assertJson(['status' => 'open']);
        $this->assertTrue(collect($reopen->json('locks'))->every(fn ($l) => $l['is_locked'] === false));
    }

    public function test_period_end_before_start_is_rejected(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $this->postJson('/api/accounting-periods', [
            'fiscal_year' => 2026, 'name' => 'Bad', 'period_start' => '2026-09-30', 'period_end' => '2026-09-01',
        ], $this->headers($token))->assertStatus(422);
    }

    public function test_overlapping_period_is_rejected(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $this->postJson('/api/accounting-periods', [
            'fiscal_year' => 2026, 'name' => 'September 2026', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
        ], $this->headers($token))->assertOk();

        $this->postJson('/api/accounting-periods', [
            'fiscal_year' => 2026, 'name' => 'Overlap', 'period_start' => '2026-09-15', 'period_end' => '2026-10-15',
        ], $this->headers($token))->assertStatus(409);
    }

    public function test_reopen_is_owner_only_even_with_full_group_authority(): void
    {
        $company = Company::factory()->create();
        $ownerToken = $this->ownerToken($company);
        $period = AccountingPeriod::create([
            'company_id' => $company->id, 'fiscal_year' => 2026, 'name' => 'Sep',
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
        ]);
        Periods::seedLocksForPeriod($period, false);
        $this->postJson("/api/accounting-periods/{$period->id}/close", [], $this->headers($ownerToken))->assertOk();

        $nonOwnerToken = $this->fullGroupToken($company);
        $this->postJson("/api/accounting-periods/{$period->id}/reopen", [], $this->headers($nonOwnerToken))->assertStatus(403);
    }

    public function test_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/accounting-periods', $this->headers($login->json('access_token')))->assertStatus(403);
    }

    public function test_view_only_group_cannot_create_a_period(): void
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
        $token = $login->json('access_token');

        $this->getJson('/api/accounting-periods', $this->headers($token))->assertOk();
        $this->postJson('/api/accounting-periods', [
            'fiscal_year' => 2026, 'name' => 'X', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
        ], $this->headers($token))->assertStatus(403);
    }

    public function test_disabled_module_is_denied_even_with_full_group_authority(): void
    {
        $company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'finance_accounting'], ['name' => 'Finance / Accounting', 'is_built' => true]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'finance_accounting', 'access_level' => GroupModuleAuthority::FULL]);
        CompanyModule::where('company_id', $company->id)->where('module_key', 'finance_accounting')->delete();
        $user = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        UserCompanyAccess::create(['user_id' => $user->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/accounting-periods', $this->headers($login->json('access_token')))->assertStatus(403);
    }

    public function test_a_period_from_another_company_is_not_found(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $tokenA = $this->ownerToken($companyA);
        $periodB = AccountingPeriod::create([
            'company_id' => $companyB->id, 'fiscal_year' => 2026, 'name' => 'Sep',
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
        ]);
        Periods::seedLocksForPeriod($periodB, false);

        $this->postJson("/api/accounting-periods/{$periodB->id}/close", [], $this->headers($tokenA))->assertStatus(404);
    }

    public function test_year_end_closing_is_owner_only(): void
    {
        $company = Company::factory()->create();
        $token = $this->fullGroupToken($company);
        $equity = Account::create(['company_id' => $company->id, 'code' => '3999', 'name' => 'Equity', 'account_type' => Account::TYPE_EQUITY]);

        $this->postJson('/api/accounting-periods/close-fiscal-year', [
            'fiscal_year' => 2026, 'retained_earnings_account_id' => $equity->id,
        ], $this->headers($token))->assertStatus(403);
    }

    public function test_owner_can_close_a_fiscal_year_end_to_end_via_the_api(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $owner = User::where('company_id', $company->id)->where('role', User::ROLE_OWNER)->firstOrFail();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $today = Carbon::today();

        $contract = ContractService::createContract(
            companyId: $company->id, customerId: $customer->id, contractedHours: 10,
            contractValueSgd: 1000, startDate: $today->toDateString(), actorUserId: $owner->id,
        );
        BillingService::issueContractAnnualInvoice($contract, $owner->id);

        $create = $this->postJson('/api/accounting-periods', [
            'fiscal_year' => $today->year, 'name' => $today->format('F Y'),
            'period_start' => $today->copy()->startOfMonth()->toDateString(),
            'period_end' => $today->copy()->endOfMonth()->toDateString(),
        ], $this->headers($token));
        $this->postJson("/api/accounting-periods/{$create->json('id')}/close", [], $this->headers($token))->assertOk();

        $equity = Account::where('company_id', $company->id)->where('code', '3100')->first()
            ?? Account::create(['company_id' => $company->id, 'code' => '3100', 'name' => 'Retained earnings', 'account_type' => Account::TYPE_EQUITY]);

        $close = $this->postJson('/api/accounting-periods/close-fiscal-year', [
            'fiscal_year' => $today->year, 'retained_earnings_account_id' => $equity->id,
        ], $this->headers($token));
        $close->assertOk()->assertJson(['fiscal_year' => $today->year, 'retained_earnings_account_id' => $equity->id]);

        $closures = $this->getJson('/api/accounting-periods/closures', $this->headers($token));
        $closures->assertOk()->assertJsonCount(1);

        // A second attempt for the same year is rejected.
        $this->postJson('/api/accounting-periods/close-fiscal-year', [
            'fiscal_year' => $today->year, 'retained_earnings_account_id' => $equity->id,
        ], $this->headers($token))->assertStatus(422);
    }
}
