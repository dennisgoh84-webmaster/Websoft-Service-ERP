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
 * Bank Portal Testing -- a module-gated placeholder (docs/backlog.md
 * "Bank Portal / ZSOFT HP Agency"). Asserts what this module exists to
 * prove: no regular staff account sees it, or can reach its route,
 * unless `bank_portal_testing` is switched on for their company AND
 * their Group is granted access to it -- the same two-gate mechanism
 * every other module in this system already uses
 * (App\Services\Authority::requireModuleAccess()). The owner role
 * bypasses both gates here too, exactly like it does for every other
 * module (Authority's own docblock) -- this is not special-cased for
 * Bank Portal Testing, so Dennis himself is never locked out of a
 * module he needs to reach in order to switch it on for anyone else.
 */
class BankPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_bypasses_module_control_same_as_every_other_module(): void
    {
        $company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'bank_portal_testing'], ['name' => 'Bank Portal Testing', 'is_built' => true]);
        $owner = User::factory()->for($company)->create([
            'role' => User::ROLE_OWNER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $token = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234'])->json('access_token');
        $h = ['Authorization' => "Bearer {$token}"];

        // No CompanyModule row at all for this company -- still reachable, same owner bypass every module has.
        $this->getJson('/api/bank-portal/status', $h)->assertOk()->assertJson(['enabled' => true]);
        $this->getJson('/api/modules/my-access', $h)->assertOk()->assertJsonPath('bank_portal_testing', true);
    }

    public function test_a_regular_staff_member_never_sees_it_until_both_gates_open(): void
    {
        $company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'bank_portal_testing'], ['name' => 'Bank Portal Testing', 'is_built' => true]);
        $group = Group::factory()->for($company)->create();
        $staff = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        UserCompanyAccess::create(['user_id' => $staff->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $token = $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234'])->json('access_token');
        $h = ['Authorization' => "Bearer {$token}"];

        // Neither Module Control nor Group Authority granted yet -- hidden, and the route itself refuses.
        $this->getJson('/api/modules/my-access', $h)->assertOk()->assertJsonPath('bank_portal_testing', false);
        $this->getJson('/api/bank-portal/status', $h)->assertStatus(403);

        // Module switched on company-wide, but this staff member's group still has no access to it -- still hidden.
        CompanyModule::create(['company_id' => $company->id, 'module_key' => 'bank_portal_testing', 'enabled' => true, 'license_type' => CompanyModule::INCLUDED]);
        $this->getJson('/api/modules/my-access', $h)->assertOk()->assertJsonPath('bank_portal_testing', false);
        $this->getJson('/api/bank-portal/status', $h)->assertStatus(403);

        // Only once BOTH are true -- Module Control on, and their Group granted VIEW -- do they see it.
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'bank_portal_testing', 'access_level' => GroupModuleAuthority::VIEW]);
        $this->getJson('/api/modules/my-access', $h)->assertOk()->assertJsonPath('bank_portal_testing', true);
        $this->getJson('/api/bank-portal/status', $h)->assertOk()->assertJson(['enabled' => true]);
    }
}
