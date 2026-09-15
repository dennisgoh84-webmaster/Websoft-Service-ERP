<?php

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\TaxCode;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers App\Http\Controllers\Api\TaxCodeController -- converted from
 * backend/app/routers/tax_codes.py -- and App\Services\Exports, the
 * port of backend/app/services/exports.py that this is the first
 * converted module to use.
 */
class TaxCodeTest extends TestCase
{
    use RefreshDatabase;

    private const MODULE = 'finance_accounting';

    private function ownerToken(Company $company): string
    {
        $owner = User::factory()->for($company)->create([
            'role' => User::ROLE_OWNER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        ModuleCatalog::firstOrCreate(['key' => self::MODULE], ['name' => 'Finance / Accounting', 'is_built' => true]);
        CompanyModule::updateOrCreate(
            ['company_id' => $company->id, 'module_key' => self::MODULE],
            ['enabled' => true],
        );
        $login = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234']);

        return $login->json('access_token');
    }

    private function tokenForLevel(Company $company, string $level): string
    {
        ModuleCatalog::firstOrCreate(['key' => self::MODULE], ['name' => 'Finance / Accounting', 'is_built' => true]);
        CompanyModule::updateOrCreate(
            ['company_id' => $company->id, 'module_key' => self::MODULE],
            ['enabled' => true],
        );
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create([
            'group_id' => $group->id, 'module_key' => self::MODULE, 'access_level' => $level,
        ]);
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

    public function test_list_hides_inactive_unless_asked_and_orders_by_code(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        TaxCode::create(['company_id' => $company->id, 'code' => 'ZR', 'name' => 'Zero Rated', 'rate_percent' => 0]);
        TaxCode::create(['company_id' => $company->id, 'code' => 'SR', 'name' => 'Standard Rated', 'rate_percent' => 9]);
        TaxCode::create(['company_id' => $company->id, 'code' => 'OLD', 'name' => 'Retired', 'rate_percent' => 7, 'is_active' => false]);

        $this->getJson('/api/tax-codes', $this->headers($token))
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.code', 'SR')
            ->assertJsonPath('1.code', 'ZR');

        $this->getJson('/api/tax-codes?include_inactive=true', $this->headers($token))
            ->assertOk()
            ->assertJsonCount(3)
            // Ordered by code, so OLD sorts first.
            ->assertJsonPath('0.code', 'OLD');
    }

    public function test_rate_percent_is_a_bare_number_not_a_string(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        TaxCode::create(['company_id' => $company->id, 'code' => 'SR', 'name' => 'Standard', 'rate_percent' => 9]);

        // Python's TaxCodeOut types rate_percent as float, so the JSON
        // must carry a NUMBER, not the numeric string Eloquent's
        // decimal:2 cast would otherwise produce -- the frontend does
        // arithmetic on it.
        //
        // Note: PHP's json_encode writes 9.0 as `9` where Python writes
        // `9.0`. Both parse to the same JS Number, so this is not a
        // contract difference, and no converted module in this codebase
        // sets JSON_PRESERVE_ZERO_FRACTION to chase it. What matters --
        // and what is pinned here -- is that it is not a string.
        $body = $this->getJson('/api/tax-codes', $this->headers($token))->assertOk()->json();
        $this->assertIsNotString($body[0]['rate_percent']);
        $this->assertEqualsWithDelta(9, $body[0]['rate_percent'], 0.0001);
    }

    public function test_create_rejects_a_duplicate_code_with_409(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        TaxCode::create(['company_id' => $company->id, 'code' => 'SR', 'name' => 'Standard', 'rate_percent' => 9]);

        $this->postJson('/api/tax-codes', ['code' => 'SR', 'name' => 'Duplicate', 'rate_percent' => 9], $this->headers($token))
            ->assertStatus(409)
            ->assertJsonPath('detail', 'Tax code SR already exists.');
    }

    public function test_create_is_active_defaults_true_and_cannot_be_set_on_create(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        // Python's TaxCodeCreate has no is_active field, so passing one
        // is simply not accepted into the record -- it is not a way to
        // create a pre-deactivated tax code.
        $this->postJson('/api/tax-codes', [
            'code' => 'SR', 'name' => 'Standard', 'rate_percent' => 9, 'is_active' => false,
        ], $this->headers($token))->assertOk()->assertJsonPath('is_active', true);
    }

    public function test_rate_percent_is_bounded_zero_to_one_hundred(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $this->postJson('/api/tax-codes', ['code' => 'X1', 'name' => 'Too high', 'rate_percent' => 101], $this->headers($token))
            ->assertStatus(422);
        $this->postJson('/api/tax-codes', ['code' => 'X2', 'name' => 'Negative', 'rate_percent' => -1], $this->headers($token))
            ->assertStatus(422);
    }

    public function test_update_records_only_changed_fields_in_the_audit_trail(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $taxCode = TaxCode::create(['company_id' => $company->id, 'code' => 'SR', 'name' => 'Standard', 'rate_percent' => 9]);

        // Same name, new rate -- Python compares old != new per field and
        // records only what actually moved.
        $this->patchJson("/api/tax-codes/{$taxCode->id}", ['name' => 'Standard', 'rate_percent' => 10], $this->headers($token))
            ->assertOk();

        $entry = AuditLogEntry::where('entity_type', 'tax_code')->where('action', 'updated')->firstOrFail();
        // AuditLogEntry does not cast new_value, so it arrives as raw JSON text.
        $newValue = json_decode((string) $entry->new_value, true);
        $this->assertArrayHasKey('rate_percent', $newValue);
        $this->assertArrayNotHasKey('name', $newValue, 'an unchanged field must not be recorded as changed');
    }

    public function test_another_companys_tax_code_is_not_found(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $token = $this->ownerToken($company);
        $theirs = TaxCode::create(['company_id' => $other->id, 'code' => 'SR', 'name' => 'Theirs', 'rate_percent' => 9]);

        $this->patchJson("/api/tax-codes/{$theirs->id}", ['name' => 'Hijacked'], $this->headers($token))
            ->assertStatus(404);
    }

    public function test_view_level_can_read_and_export_but_not_edit(): void
    {
        $company = Company::factory()->create();
        $token = $this->tokenForLevel($company, GroupModuleAuthority::VIEW);
        TaxCode::create(['company_id' => $company->id, 'code' => 'SR', 'name' => 'Standard', 'rate_percent' => 9]);

        $this->getJson('/api/tax-codes', $this->headers($token))->assertOk();
        $this->get('/api/tax-codes/export.csv', $this->headers($token))->assertOk();
        $this->postJson('/api/tax-codes', ['code' => 'NEW', 'name' => 'Nope', 'rate_percent' => 1], $this->headers($token))
            ->assertStatus(403);
    }

    public function test_csv_export_has_the_python_header_row_and_python_bool_spelling(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        TaxCode::create(['company_id' => $company->id, 'code' => 'SR', 'name' => 'Standard Rated', 'rate_percent' => 9]);

        $response = $this->get('/api/tax-codes/export.csv', $this->headers($token))->assertOk();
        $response->assertHeader('Content-Disposition', 'attachment; filename=tax-types.csv');
        $csv = $response->getContent();

        $this->assertStringContainsString('code,name,rate_percent,is_active', $csv);
        // Python's csv.DictWriter stringifies a bool as True/False, not 1/0.
        $this->assertStringContainsString('SR,"Standard Rated",9.00,True', str_replace('"Standard Rated"', '"Standard Rated"', $csv));
    }

    public function test_xlsx_export_is_a_real_xlsx_package_not_an_html_table(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        TaxCode::create(['company_id' => $company->id, 'code' => 'SR', 'name' => 'Standard Rated', 'rate_percent' => 9]);

        $response = $this->get('/api/tax-codes/export.xlsx', $this->headers($token))->assertOk();
        $response->assertHeader('Content-Disposition', 'attachment; filename=tax-types.xlsx');
        $bytes = $response->getContent();

        // A real OOXML package is a ZIP: "PK\x03\x04". The pre-existing
        // App\Services\ExportService writes an HTML <table> instead,
        // which is why a converted endpoint cannot use it -- Python's
        // openpyxl returns a genuine .xlsx here.
        $this->assertStringStartsWith("PK\x03\x04", $bytes);

        $tmp = tempnam(sys_get_temp_dir(), 'xlsxtest');
        file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($tmp) === true, 'export is not a readable zip package');
        $this->assertNotFalse($zip->locateName('xl/workbook.xml'), 'missing the OOXML workbook part');
        // Cell text lives in the shared-string table, not the sheet --
        // itself a sign this is a genuine OOXML package.
        $shared = $zip->getFromName('xl/sharedStrings.xml');
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($tmp);
        $this->assertStringContainsString('Standard Rated', (string) $shared);
        $this->assertStringContainsString('rate_percent', (string) $shared, 'header row missing');
        // Python bolds the header row; so does App\Services\Exports.
        $this->assertStringContainsString('<c r="A1" s="1"', (string) $sheet, 'header row is not styled');
    }

    public function test_disabled_module_is_denied_even_with_full_group_authority(): void
    {
        $company = Company::factory()->create();
        $token = $this->tokenForLevel($company, GroupModuleAuthority::FULL);
        CompanyModule::updateOrCreate(
            ['company_id' => $company->id, 'module_key' => self::MODULE],
            ['enabled' => false],
        );

        // Note the deliberate asymmetry in Authority::requireModuleAccess:
        // an OWNER bypasses Module Control entirely, so this case must be
        // driven by a group-authority user, not an owner.
        $this->getJson('/api/tax-codes', $this->headers($token))->assertStatus(403);
    }
}
