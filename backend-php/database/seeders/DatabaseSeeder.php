<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\TaxCode;
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
        'crm' => ['CRM', true, true],
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

    // A conventional Singapore SME chart of accounts, seeded as a
    // STARTING POINT (confirmed approach with Dennis, 2026-09-10) --
    // not a decided chart. Same list as
    // backend/scripts/seed_demo.py's CHART_OF_ACCOUNTS.
    private const CHART_OF_ACCOUNTS = [
        // Assets (1xxx)
        ['1000', 'Cash at bank', Account::TYPE_ASSET],
        ['1010', 'Petty cash', Account::TYPE_ASSET],
        ['1100', 'Accounts receivable', Account::TYPE_ASSET],
        ['1150', 'Accrued revenue', Account::TYPE_ASSET],
        ['1200', 'Prepayments', Account::TYPE_ASSET],
        ['1300', 'Inventory', Account::TYPE_ASSET],
        ['1500', 'Office equipment', Account::TYPE_ASSET],
        ['1510', 'Accumulated depreciation -- office equipment', Account::TYPE_ASSET],
        // Liabilities (2xxx)
        ['2000', 'Accounts payable', Account::TYPE_LIABILITY],
        ['2100', 'GST output tax (collected on sales)', Account::TYPE_LIABILITY],
        ['2110', 'GST input tax (paid on purchases)', Account::TYPE_LIABILITY],
        ['2200', 'Accruals', Account::TYPE_LIABILITY],
        ['2300', 'Deferred revenue (unearned contract income)', Account::TYPE_LIABILITY],
        ['2400', 'CPF payable', Account::TYPE_LIABILITY],
        ['2500', 'Corporate tax payable', Account::TYPE_LIABILITY],
        // Equity (3xxx)
        ['3000', 'Share capital', Account::TYPE_EQUITY],
        ['3100', 'Retained earnings', Account::TYPE_EQUITY],
        // Revenue (4xxx)
        ['4000', 'Service contract revenue', Account::TYPE_REVENUE],
        ['4010', 'Excess usage revenue', Account::TYPE_REVENUE],
        ['4020', 'Project revenue', Account::TYPE_REVENUE],
        ['4030', 'Hardware sales', Account::TYPE_REVENUE],
        ['4900', 'Other income', Account::TYPE_REVENUE],
        // Expenses (5xxx-6xxx)
        ['5000', 'Cost of services', Account::TYPE_EXPENSE],
        ['5010', 'Cost of hardware sold', Account::TYPE_EXPENSE],
        ['5020', 'Subcontractor costs', Account::TYPE_EXPENSE],
        ['6000', 'Salaries and wages', Account::TYPE_EXPENSE],
        ['6010', 'CPF contributions', Account::TYPE_EXPENSE],
        ['6100', 'Rent', Account::TYPE_EXPENSE],
        ['6110', 'Utilities', Account::TYPE_EXPENSE],
        ['6200', 'Software and subscriptions', Account::TYPE_EXPENSE],
        ['6300', 'Professional fees', Account::TYPE_EXPENSE],
        ['6400', 'Marketing', Account::TYPE_EXPENSE],
        ['6500', 'Bank charges', Account::TYPE_EXPENSE],
        ['6600', 'Depreciation', Account::TYPE_EXPENSE],
        ['6700', 'Bad debts written off', Account::TYPE_EXPENSE],
        ['6900', 'Other operating expenses', Account::TYPE_EXPENSE],
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

        // GST tax codes. Confirmed 2026-09-10: Webmaster is
        // GST-registered and its services are standard-rated (SR). The
        // others are seeded inactive-ready so a future zero-rated/
        // exempt supply doesn't need a code change -- same list as
        // backend/scripts/seed_demo.py's TAX_CODES.
        foreach ([
            ['SR', 'Standard-rated supply', '9.00'],
            ['ZR', 'Zero-rated supply (e.g. export of services)', '0.00'],
            ['ES', 'Exempt supply', '0.00'],
            ['OS', 'Out of scope', '0.00'],
        ] as [$code, $name, $rate]) {
            TaxCode::updateOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                ['name' => $name, 'rate_percent' => $rate, 'is_active' => true],
            );
        }

        // Chart of Accounts + a default bank account, so GL posting
        // has somewhere to post to and a Payment Voucher has a bank
        // account to select -- see this class's CHART_OF_ACCOUNTS.
        foreach (self::CHART_OF_ACCOUNTS as [$code, $name, $accountType]) {
            Account::updateOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                ['name' => $name, 'account_type' => $accountType, 'is_active' => true],
            );
        }
        $cashAtBank = Account::where('company_id', $company->id)->where('code', '1000')->first();
        BankAccount::firstOrCreate(
            ['company_id' => $company->id, 'account_number' => '123-456789-0'],
            [
                'bank_name' => 'DBS Bank',
                'account_name' => 'Webmaster Consultancy Pte Ltd',
                'currency_code' => 'SGD',
                'gl_account_id' => $cashAtBank?->id,
            ],
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

        $this->command?->info('Seeded: 1 company, module catalog, Owner/Admin group, Dennis (owner, demo1234), Chart of Accounts, 1 bank account, 1 sample customer.');
    }
}
