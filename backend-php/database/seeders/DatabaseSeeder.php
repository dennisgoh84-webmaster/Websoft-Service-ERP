<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Database\Seeder;

/**
 * A small demo dataset for the modules converted so far (Core /
 * Administration, Group Authority, Module Control, CompanyIndividual
 * Management) -- mirrors the relevant parts of
 * backend/scripts/seed_demo.py's MODULE_CATALOG and GROUP_CATALOG.
 * Not a full port of that script -- most of it seeds modules not yet
 * converted here (contracts, job orders, billing, etc.), see
 * docs/php-conversion-plan.md.
 *
 * Run with: php artisan db:seed
 */
class DatabaseSeeder extends Seeder
{
    // key => [name, is_built, enabled]. Same catalog as
    // backend/scripts/seed_demo.py's MODULE_CATALOG (is_built/enabled
    // reflect what actually has application code today in the *Python*
    // backend; this PHP backend has application code for a smaller
    // subset so far -- see docs/php-conversion-plan.md for what that is).
    private const MODULE_CATALOG = [
        'core_administration' => ['Core / Administration', true, true],
        'event_logs' => ['Event Logs', true, true],
        'crm' => ['CRM', false, false],
        'sales' => ['Sales (Quotations, Product/Service Catalog)', true, true],
        'company_individual_management' => ['Customer Management', true, true],
        'service_contracts' => ['Service Contracts', true, true],
        'service_operations' => ['Helpdesk / Service Operations (Job Orders)', true, true],
        'projects' => ['Projects', false, false],
        'service_records' => ['Service Records', true, true],
        'billing' => ['Billing', true, true],
        'accounts_receivable' => ['Accounts Receivable', true, true],
        'accounts_payable' => ['Accounts Payable', true, true],
        'purchasing' => ['Purchasing', true, true],
        'inventory' => ['Inventory', false, false],
        'hardware_management' => ['Hardware Management', false, false],
        'commission_management' => ['Commission Management', false, false], // deferred
        'finance_accounting' => ['Finance / Accounting', true, true],
        'reporting' => ['Reporting / Management Dashboard', true, true],
        'software_development' => ['Software Development (Software Tasks)', true, true],
        'operations_reports' => ['Operations Reports (Contracts / Job Orders / Service Records)', true, true],
        'accounting_reports' => ['Accounting Reports (AR/AP Aging, Trial Balance)', true, true],
        'integrations' => ['Integrations (incl. Odoo migration)', false, false], // deferred
        'ai_assistant' => ['AI Assistant', false, false],
        'stock_master' => ['Stock Master', true, true],
        'goods_receive_note' => ['Goods Receive Note', true, true],
        'goods_transfer_note' => ['Goods Transfer Note', true, true],
        'goods_return_note' => ['Goods Return Note', true, true],
        'stock_adjustment' => ['Stock Adjustment', true, true],
        'stock_operation_reports' => ['Stock Operation Reports', true, true],
        'ops_dashboard' => ['Ops Dashboard (personal task tracker)', true, true],
    ];

    public function run(): void
    {
        foreach (self::MODULE_CATALOG as $key => [$name, $isBuilt, $_enabled]) {
            ModuleCatalog::updateOrCreate(['key' => $key], ['name' => $name, 'is_built' => $isBuilt]);
        }

        $company = Company::firstOrCreate(
            ['name' => 'Webmaster Consultancy Pte Ltd'],
            [
                'country' => 'Singapore',
                'currency' => 'SGD',
                'timezone' => 'Asia/Singapore',
            ],
        );

        foreach (self::MODULE_CATALOG as $key => [$name, $_isBuilt, $enabled]) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->id, 'module_key' => $key],
                ['enabled' => $enabled, 'license_type' => CompanyModule::INCLUDED],
            );
        }

        $adminGroup = Group::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Owner / Admin'],
            ['description' => 'Full access to every module. Created with the company.'],
        );
        foreach (self::MODULE_CATALOG as $key => [$name, $isBuilt, $_enabled]) {
            if (! $isBuilt) {
                continue;
            }
            GroupModuleAuthority::updateOrCreate(
                ['group_id' => $adminGroup->id, 'module_key' => $key],
                ['access_level' => GroupModuleAuthority::FULL],
            );
        }

        // Demo owner user -- password "demo1234", same as
        // backend/scripts/seed_demo.py's seeded users.
        $dennis = User::firstOrCreate(
            ['email' => 'dennis@websoft.example'],
            [
                'company_id' => $company->id,
                'hashed_password' => PasswordPolicy::hash('demo1234'),
                'full_name' => 'Dennis',
                'role' => User::ROLE_OWNER,
                'must_change_password' => false,
            ],
        );
        UserCompanyAccess::firstOrCreate(
            ['user_id' => $dennis->id, 'company_id' => $company->id],
            ['group_id' => $adminGroup->id],
        );

        // One sample customer, so the CompanyIndividual Management
        // slice has something to look at immediately after seeding.
        CompanyIndividual::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Acme Manufacturing Pte Ltd'],
            [
                'customer_type' => CompanyIndividual::TYPE_COMPANY,
                'contact_person' => 'Tan Wei Ming',
                'billing_email' => 'accounts@acme.example',
                'phone' => '+65 6123 4567',
                'payment_terms_days' => 30,
            ],
        );

        $this->command?->info('Seeded: 1 company, module catalog, Owner/Admin group, Dennis (owner, demo1234), 1 sample customer.');
    }
}
