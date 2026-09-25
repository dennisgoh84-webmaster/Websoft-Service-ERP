<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Data Migration API (App\Http\Controllers\Api\DataMigrationController):
 * gated on `data_migration` (VIEW looks, FULL acts), scoped to the
 * Internal Company chosen, every step reachable over HTTP.
 */
class DataMigrationApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        ModuleCatalog::firstOrCreate(['key' => 'data_migration'], ['name' => 'Data Migration', 'is_built' => true]);
        $this->company = Company::factory()->create();
    }

    /** @return array{0: User, 1: array<string, string>} */
    private function login(Company $company, string $role, ?string $level = null): array
    {
        $user = User::factory()->for($company)->create(['role' => $role, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        if ($level !== null) {
            CompanyModule::updateOrCreate(['company_id' => $company->id, 'module_key' => 'data_migration'], ['enabled' => true]);
            $group = Group::factory()->for($company)->create();
            GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'data_migration', 'access_level' => $level]);
            UserCompanyAccess::create(['user_id' => $user->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        }
        $token = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234'])->json('access_token');

        return [$user, ['Authorization' => "Bearer {$token}"]];
    }

    private function file(string $csv, string $name = 'contacts.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $csv);
    }

    public function test_the_whole_flow_over_http(): void
    {
        [, $h] = $this->login($this->company, User::ROLE_OWNER);

        $upload = $this->post('/api/data-migration/batches', [
            'company_id' => $this->company->id, 'source' => 'zsoft', 'entity' => 'company_individuals',
            'file' => $this->file("CUST_CODE,CUST_NAME,CREDIT_LIMIT\r\nC001,Harbour Logistics Pte Ltd,5000\r\nC002,Tan Ah Kow,0\r\n"),
        ], $h)->assertCreated();
        $id = $upload->json('id');
        $this->assertSame(2, $upload->json('rows_read'));

        $mapping = $this->getJson("/api/data-migration/modules/zsoft/company_individuals/mapping?company_id={$this->company->id}", $h)->assertOk();
        $this->assertSame(1, $mapping->json('undecided'));
        $this->assertFalse($mapping->json('can_sign_off'));

        $this->postJson("/api/data-migration/batches/{$id}/dry-run", [], $h)->assertOk()->assertJson(['status' => 'dry_run', 'rows_created' => 2]);
        $this->postJson("/api/data-migration/batches/{$id}/import", [], $h)->assertStatus(422);

        $this->putJson('/api/data-migration/modules/zsoft/company_individuals/mapping', [
            'company_id' => $this->company->id, 'mapping' => ['CREDIT_LIMIT' => '__new_field__'],
        ], $h)->assertOk()->assertJson(['gaps' => 1]);
        $this->get("/api/data-migration/modules/zsoft/company_individuals/field-gap.csv?company_id={$this->company->id}", $h)
            ->assertOk()->assertSee('CREDIT_LIMIT,Field Gap: needs a new field', false);
        $this->postJson('/api/data-migration/modules/zsoft/company_individuals/sign-off', ['company_id' => $this->company->id], $h)->assertStatus(422);

        $this->putJson('/api/data-migration/modules/zsoft/company_individuals/mapping', [
            'company_id' => $this->company->id, 'mapping' => ['CREDIT_LIMIT' => '__skip__'],
        ], $h)->assertOk();
        $this->postJson('/api/data-migration/modules/zsoft/company_individuals/sign-off', ['company_id' => $this->company->id], $h)
            ->assertOk()->assertJson(['signed_off' => true]);

        $this->postJson("/api/data-migration/batches/{$id}/dry-run", [], $h)->assertOk();
        $this->postJson("/api/data-migration/batches/{$id}/import", [], $h)->assertOk()->assertJson(['status' => 'succeeded', 'status_label' => 'Imported']);
        $this->assertSame(2, CompanyIndividual::where('company_id', $this->company->id)->count());

        $overview = $this->getJson("/api/data-migration/overview?company_id={$this->company->id}", $h)->assertOk();
        $module = collect($overview->json('modules'))->firstWhere(fn ($m) => $m['source'] === 'zsoft' && $m['entity'] === 'company_individuals');
        $this->assertEquals(['imported' => 2, 'total' => 2, 'status' => 'complete'], array_intersect_key($module, ['imported' => 1, 'total' => 1, 'status' => 1]));
        $this->assertSame(11, $overview->json('totals.modules'));

        $this->getJson('/api/data-migration/batches', $h)->assertOk()->assertJsonCount(1);
        $this->get('/api/data-migration/batches/export.xlsx', $h)->assertOk();

        $this->postJson("/api/data-migration/batches/{$id}/rollback", ['reason' => 'Test load'], $h)
            ->assertOk()->assertJson(['rolled_back' => true, 'removed' => 2, 'batch' => ['status' => 'rolled_back']]);
        $this->assertSame(0, CompanyIndividual::where('company_id', $this->company->id)->count());
    }

    public function test_view_can_look_but_not_act(): void
    {
        [, $h] = $this->login($this->company, User::ROLE_SUPPORT_ENGINEER, GroupModuleAuthority::VIEW);

        $this->getJson('/api/data-migration/overview', $h)->assertOk();
        $this->post('/api/data-migration/batches', [
            'source' => 'odoo', 'entity' => 'company_individuals', 'file' => $this->file("id,name\r\np1,Acme\r\n"),
        ], $h)->assertStatus(403);
    }

    public function test_no_authority_sees_nothing(): void
    {
        [, $h] = $this->login($this->company, User::ROLE_SUPPORT_ENGINEER);

        $this->getJson('/api/data-migration/overview', $h)->assertStatus(403);
    }

    public function test_an_internal_company_you_cannot_open_is_refused(): void
    {
        [, $h] = $this->login($this->company, User::ROLE_SUPPORT_ENGINEER, GroupModuleAuthority::FULL);
        $other = Company::factory()->create();

        $this->getJson("/api/data-migration/overview?company_id={$other->id}", $h)->assertStatus(403);
        $this->post('/api/data-migration/batches', [
            'company_id' => $other->id, 'source' => 'odoo', 'entity' => 'company_individuals', 'file' => $this->file("id,name\r\np1,Acme\r\n"),
        ], $h)->assertStatus(403);
    }

    public function test_only_excel_or_csv_is_accepted(): void
    {
        [, $h] = $this->login($this->company, User::ROLE_OWNER);

        $this->post('/api/data-migration/batches', [
            'source' => 'odoo', 'entity' => 'company_individuals', 'file' => $this->file('hello', 'notes.txt'),
        ], $h)->assertStatus(422);
        $this->post('/api/data-migration/batches', [
            'source' => 'odoo', 'entity' => 'job_orders', 'file' => $this->file("id,name\r\n"),
        ], $h)->assertStatus(404);
    }
}
