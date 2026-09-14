<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API-level coverage of App\Http\Controllers\Api\ReportController --
 * the trial-balance duplicate carried over from
 * backend/app/routers/reports.py. See that class's docblock for why
 * it exists alongside LedgerController::trialBalance() rather than
 * reusing its route, and docs/php-conversion-plan.md's "after
 * converting each module" checklist.
 */
class AccountingReportsTest extends TestCase
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

    public function test_owner_can_view_the_trial_balance_report(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $response = $this->getJson('/api/reports/accounting/trial-balance', $this->headers($token));

        $response->assertOk()->assertJson(['is_balanced' => true, 'rows' => []]);
    }

    /**
     * This route is gated by the `accounting_reports` module key, NOT
     * `finance_accounting` (see ReportController's FINDING docblock)
     * -- a Group with FULL authority on finance_accounting alone must
     * still be denied here.
     */
    public function test_finance_accounting_authority_alone_does_not_grant_this_report(): void
    {
        $company = Company::factory()->create();
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

        $this->getJson('/api/reports/accounting/trial-balance', $this->headers($login->json('access_token')))
            ->assertStatus(403);
    }

    public function test_accounting_reports_authority_grants_this_report(): void
    {
        $company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'accounting_reports'], ['name' => 'Accounting Reports', 'is_built' => true]);
        CompanyModule::updateOrCreate(['company_id' => $company->id, 'module_key' => 'accounting_reports'], ['enabled' => true]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'accounting_reports', 'access_level' => GroupModuleAuthority::VIEW]);
        $user = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        UserCompanyAccess::create(['user_id' => $user->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/reports/accounting/trial-balance', $this->headers($login->json('access_token')))
            ->assertOk();
    }

    public function test_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/reports/accounting/trial-balance', $this->headers($login->json('access_token')))
            ->assertStatus(403);
    }
}
