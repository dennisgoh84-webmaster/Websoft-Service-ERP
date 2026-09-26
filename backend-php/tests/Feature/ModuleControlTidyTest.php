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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Module Control tidy-up (Dennis, 2026-09-26): Purchasing, Projects,
 * Hardware Management, Inventory and Integrations are gone; Commission
 * Management owns the commission report, its rate and Commission
 * Payouts.
 */
class ModuleControlTidyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_removed_modules_are_gone_and_commission_management_is_built(): void
    {
        foreach (['purchasing', 'projects', 'hardware_management', 'inventory', 'integrations'] as $key) {
            $this->assertDatabaseMissing('modules', ['key' => $key]);
        }
        $this->assertDatabaseHas('modules', ['key' => 'commission_management', 'is_built' => true]);
    }

    public function test_the_migration_carries_accounting_reports_access_over_to_commission_management(): void
    {
        $company = Company::factory()->create();
        $off = Company::factory()->create();
        $group = Group::factory()->for($company)->create();
        ModuleCatalog::firstOrCreate(['key' => 'accounting_reports'], ['name' => 'Accounting Reports', 'is_built' => true]);
        ModuleCatalog::firstOrCreate(['key' => 'purchasing'], ['name' => 'Purchasing', 'is_built' => true]);
        CompanyModule::create(['company_id' => $company->id, 'module_key' => 'accounting_reports', 'enabled' => true]);
        CompanyModule::create(['company_id' => $off->id, 'module_key' => 'accounting_reports', 'enabled' => false]);
        CompanyModule::create(['company_id' => $company->id, 'module_key' => 'purchasing', 'enabled' => true]);
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'accounting_reports', 'access_level' => GroupModuleAuthority::FULL]);
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'purchasing', 'access_level' => GroupModuleAuthority::FULL]);

        (require database_path('migrations/2026_09_30_002400_tidy_module_control.php'))->up();

        $this->assertDatabaseMissing('modules', ['key' => 'purchasing']);
        $this->assertSame(0, DB::table('company_modules')->where('module_key', 'purchasing')->count());
        $this->assertTrue((bool) CompanyModule::where('company_id', $company->id)->where('module_key', 'commission_management')->value('enabled'));
        $this->assertFalse((bool) CompanyModule::where('company_id', $off->id)->where('module_key', 'commission_management')->value('enabled'));
        $this->assertSame(GroupModuleAuthority::FULL, GroupModuleAuthority::where('group_id', $group->id)->where('module_key', 'commission_management')->value('access_level'));
    }

    public function test_the_inventory_and_integrations_placeholders_are_removed_with_their_settings(): void
    {
        $company = Company::factory()->create();
        $group = Group::factory()->for($company)->create();
        foreach (['inventory', 'integrations'] as $key) {
            ModuleCatalog::firstOrCreate(['key' => $key], ['name' => $key, 'is_built' => false]);
            CompanyModule::create(['company_id' => $company->id, 'module_key' => $key, 'enabled' => false]);
            GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => $key, 'access_level' => GroupModuleAuthority::VIEW]);
        }
        foreach (['stock_master', 'data_migration'] as $key) {
            ModuleCatalog::firstOrCreate(['key' => $key], ['name' => $key, 'is_built' => true]);
        }

        (require database_path('migrations/2026_09_30_002600_remove_inventory_and_integrations_modules.php'))->up();

        foreach (['inventory', 'integrations'] as $key) {
            $this->assertDatabaseMissing('modules', ['key' => $key]);
            $this->assertDatabaseMissing('company_modules', ['module_key' => $key]);
            $this->assertDatabaseMissing('group_module_authorities', ['module_key' => $key]);
        }
        // The stock and data-migration modules that do the real work stay.
        $this->assertDatabaseHas('modules', ['key' => 'stock_master']);
        $this->assertDatabaseHas('modules', ['key' => 'data_migration']);
    }

    public function test_accounting_reports_alone_no_longer_opens_commission(): void
    {
        $company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'accounting_reports'], ['name' => 'Accounting Reports', 'is_built' => true]);
        CompanyModule::create(['company_id' => $company->id, 'module_key' => 'accounting_reports', 'enabled' => true]);
        CompanyModule::create(['company_id' => $company->id, 'module_key' => 'commission_management', 'enabled' => true]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'accounting_reports', 'access_level' => GroupModuleAuthority::FULL]);
        $staff = User::factory()->for($company)->create(['role' => User::ROLE_FINANCE, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        UserCompanyAccess::create(['user_id' => $staff->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $h = ['Authorization' => 'Bearer '.$this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234'])->json('access_token')];

        $this->getJson('/api/reports/accounting/commission-settings', $h)->assertStatus(403);
        $this->getJson('/api/reports/accounting/commission?period_start=2026-09-01&period_end=2026-09-30', $h)->assertStatus(403);
        $this->getJson('/api/commissions/payouts', $h)->assertStatus(403);
        $this->getJson('/api/reports/accounting/sales-gp?period_start=2026-09-01&period_end=2026-09-30', $h)->assertOk();

        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'commission_management', 'access_level' => GroupModuleAuthority::VIEW]);
        $this->getJson('/api/reports/accounting/commission-settings', $h)->assertOk();
        $this->getJson('/api/commissions/payouts', $h)->assertOk();
    }
}
