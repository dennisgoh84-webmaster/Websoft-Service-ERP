<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\AdBannerSettings;
use App\Models\Announcement;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\SetupListItem;
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
        'prospects' => ['Prospect / Leads', true, true],
        'sales' => ['Sales (Quotations, Product/Service Catalog)', true, true],
        'company_individual_management' => ['Customer Management', true, true],
        'service_contracts' => ['Service Contracts', true, true],
        'service_operations' => ['Helpdesk / Service Operations (Job Orders)', true, true],
        'service_records' => ['Service Records', true, true],
        'billing' => ['Billing', true, true],
        'accounts_receivable' => ['Accounts Receivable', true, true],
        'accounts_payable' => ['Accounts Payable', true, true],
        // Commission report, rate and Payouts (moved from accounting_reports 2026-09-26).
        'commission_management' => ['Commission Management', true, true],
        'finance_accounting' => ['Finance / Accounting', true, true],
        'reporting' => ['Reporting / Management Dashboard', true, true],
        'software_development' => ['Software Development (Software Tasks)', true, true],
        'operations_reports' => ['Operations Reports (Contracts / Job Orders / Service Records)', true, true],
        'accounting_reports' => ['Accounting Reports (AR/AP Aging, Trial Balance)', true, true],
        // Built 2026-09-15 (incident triage). A paid add-on (decision 12.4):
        // seeded OFF; enable per company under Module Control once licensed.
        'ai_assistant' => ['AI Assistant', true, false],
        'stock_master' => ['Stock Master', true, true],
        'goods_receive_note' => ['Goods Receive Note', true, true],
        'goods_transfer_note' => ['Goods Transfer Note', true, true],
        'goods_return_note' => ['Goods Return Note', true, true],
        'goods_issue_note' => ['Goods Issue Note', true, true],
        'stock_adjustment' => ['Stock Adjustment', true, true],
        'stock_operation_reports' => ['Stock Operation Reports', true, true],
        'ops_dashboard' => ['Ops Dashboard (personal task tracker)', true, true],
        // Built 2026-09-16 as a gated placeholder only -- docs/backlog.md
        // "Bank Portal / ZSOFT HP Agency" still needs Dennis to say what
        // this actually is before real functionality is built. Seeded
        // OFF, same as ai_assistant: enable per company under Module
        // Control once there's something to test.
        'bank_portal_testing' => ['Bank Portal Testing', true, false],
        // Built 2026-09-25 (docs/data-migration.md). Switch off under
        // Module Control once the ODOO/ZSOFT cut-over is finished.
        'data_migration' => ['Data Migration (ODOO / ZSOFT)', true, true],
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

    /**
     * The real Webmaster Consultancy letterhead, from Dennis's own
     * Quotation (Quote_0160, shared 2026-09-10) -- the same values
     * backend/scripts/seed_demo.py seeds, not invented ones. They print
     * on quotations and invoices, and are editable in Company Setup.
     */
    private const COMPANY_LETTERHEAD = [
        'address' => '8 Ubi Road 2 #05-12/13/14 Zervex, Singapore 408538',
        'phone' => '6709 1233 / 6747 0705',
        'website' => 'www.websoft.sg',
        'uen' => '199802145E',
        'gst_registration_no' => '199802145E',
    ];

    /** The logo from that same letterhead, stored as a data URI like Company Setup's upload does. */
    private const LOGO_PATH = __DIR__.'/assets/webmaster_logo.png';

    // The ad banner and "What's New" items, global rather than
    // company-scoped (see App\Models\Announcement). Same content as
    // seed_demo.py's DEFAULT_AD_VIDEO_URL / DEFAULT_ANNOUNCEMENTS, so a
    // seeded PHP install has the same starting point the Python one
    // does and the Login page's promo panel is not empty.
    private const AD_VIDEO_URL = 'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4';

    private const ANNOUNCEMENTS = [
        ['New', 'Reference Monitor: break one Chart of Accounts code into named sub-codes for Sales Quotation lines.'],
        ['Add-on', 'Print/Email/WhatsApp actions are now icon buttons across every document list.'],
        ['Update', 'Company/Individual replaces the old "Customer" naming throughout the app.'],
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
            ] + self::COMPANY_LETTERHEAD + ['logo' => $this->logoDataUri()],
        );

        // Re-seeding an install that already has a company fills in only
        // what is still blank. Anything Dennis has since typed into
        // Company Setup -- a new address, his own logo -- is left alone:
        // a seeder must not overwrite entered data.
        $filled = [];
        foreach (self::COMPANY_LETTERHEAD + ['logo' => $this->logoDataUri()] as $field => $value) {
            if ($value !== null && ($company->{$field} === null || $company->{$field} === '')) {
                $company->{$field} = $value;
                $filled[] = $field;
            }
        }
        if ($filled !== []) {
            $company->save();
        }

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
                'username' => 'dennis',
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

        // The promo video + "What's New" panel on the Login page and on
        // every signed-in page. updateOrCreate on the fixed singleton
        // row for the banner; announcements match on their text so a
        // reseed does not stack duplicates, and an item Dennis has
        // edited or deactivated keeps its sort_order/is_active.
        // Units of measure, so the Product Catalog's picker is not empty
        // on day one. "Hours" matters: a quotation line in Hours is what
        // becomes a Service Support contract on acceptance
        // (QuotationService::isHourly). Dennis adds the rest under
        // Maintenance -> Unit of Measure.
        foreach ([['HR', 'Hours'], ['UNIT', 'Unit'], ['PC', 'Piece'], ['LOT', 'Lot'], ['MTH', 'Month'], ['YR', 'Year']] as $i => [$code, $name]) {
            SetupListItem::firstOrCreate(
                ['list_type' => SetupListItem::TYPE_UNIT_OF_MEASURE, 'code' => $code],
                ['name' => $name, 'sort_order' => $i, 'is_active' => true],
            );
        }

        foreach (AdBannerSettings::SLOTS as $slot) {
            AdBannerSettings::firstOrCreate(['slot' => $slot], ['video_url' => self::AD_VIDEO_URL]);
        }
        foreach (self::ANNOUNCEMENTS as $i => [$tag, $text]) {
            Announcement::firstOrCreate(
                ['text' => $text],
                ['tag' => $tag, 'sort_order' => $i, 'is_active' => true],
            );
        }

        $this->command?->info('Seeded: 1 company (letterhead + logo), module catalog, Owner/Admin group, Dennis (owner, demo1234), Chart of Accounts, 1 bank account, 1 sample customer, 6 units of measure, ad banner + 3 announcements.');
    }

    /**
     * The letterhead logo as a data URI, matching how Company Setup's
     * file picker stores an upload. Returns null if the asset is
     * missing, so a checkout without it still seeds.
     */
    private function logoDataUri(): ?string
    {
        if (! is_file(self::LOGO_PATH)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode((string) file_get_contents(self::LOGO_PATH));
    }
}
