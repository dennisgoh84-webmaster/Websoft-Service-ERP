<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\ModuleCatalog;
use App\Models\User;
use App\Services\PasswordPolicy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The system-generated company code shown on Company Setup, in the
 * form Dennis specified 2026-09-15: 3 + 2 + 2 letters of the first
 * three words, then a running number from 1, zero-padded so the code
 * is eight characters -- WEBCOPT1, ACMMA001, ACM00001.
 */
class CompanyCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_code_is_three_two_two_letters_of_the_name_then_a_number_from_one(): void
    {
        $this->assertSame('WEBCOPT1', Company::factory()->create(['name' => 'Webmaster Consultancy Pte Ltd'])->code);
        // Same prefix, next number.
        $this->assertSame('WEBCOPT2', Company::factory()->create(['name' => 'Webmaster Consulting Pte Ltd'])->code);
        // Different name, its own sequence; a shorter prefix pads the number to eight characters.
        $this->assertSame('ACMMA001', Company::factory()->create(['name' => 'Acme Manufacturing'])->code);
        $this->assertSame('ACM00001', Company::factory()->create(['name' => 'Acme'])->code);
        // Letters only, case-insensitive: punctuation and case never change the code.
        $this->assertSame('WEBCOPT3', Company::factory()->create(['name' => 'web-master consultancy, pte. LTD'])->code);
    }

    public function test_the_code_is_never_typed_in_never_changed_and_survives_a_rename(): void
    {
        $company = Company::create(['name' => 'Webmaster Consultancy Pte Ltd', 'code' => 'HACK1']);
        $this->assertSame('WEBCOPT1', $company->fresh()->code);

        ModuleCatalog::firstOrCreate(['key' => 'core_administration'], ['name' => 'Core', 'is_built' => true]);
        CompanyModule::updateOrCreate(['company_id' => $company->id, 'module_key' => 'core_administration'], ['enabled' => true]);
        $owner = User::factory()->for($company)->create(['role' => User::ROLE_OWNER, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        $token = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234'])->json('access_token');
        $h = ['Authorization' => "Bearer {$token}"];

        $this->getJson('/api/companies', $h)->assertOk()->assertJsonPath('0.code', 'WEBCOPT1');

        // A rename keeps the code: it identifies the entity, not the current spelling of its name.
        $this->patchJson("/api/companies/{$company->id}", ['code' => 'HACK2', 'name' => 'Webmaster Digital Pte Ltd'], $h)
            ->assertOk()->assertJsonPath('code', 'WEBCOPT1')->assertJsonPath('name', 'Webmaster Digital Pte Ltd');

        // A company created through the screen gets its own code.
        $this->postJson('/api/companies', ['name' => 'Websoft Digital Pte Ltd'], $h)
            ->assertOk()->assertJsonPath('code', 'WEBDIPT1');
    }

    /**
     * The recoding migrations against a database that already has
     * companies -- which is every real one. 2026_09_30_000600 once
     * turned the column NOT NULL by omission (Laravel 11's change()
     * drops any modifier it is not given) and then tried to clear it,
     * so it failed on any populated database while passing on the
     * empty one the suite migrates; this runs both migrations over
     * live rows, from the placeholder C001 form they were written for.
     */
    public function test_the_recoding_migrations_run_against_existing_companies(): void
    {
        Company::factory()->create(['name' => 'Webmaster Consultancy Pte Ltd']);
        Company::factory()->create(['name' => 'Acme Manufacturing']);
        Company::factory()->create(['name' => 'Acme']);

        // Roll back every migration from the first recoding one onward,
        // however many have been added since, so this stays valid.
        $files = collect(glob(database_path('migrations/*.php')))->map(fn ($f) => basename($f))->sort()->values();
        $steps = $files->count() - $files->search(fn ($f) => str_starts_with($f, '2026_09_30_000600'));
        Artisan::call('migrate:rollback', ['--step' => $steps, '--force' => true]);
        $this->assertSame(['C001', 'C002', 'C003'], Company::query()->orderBy('code')->pluck('code')->all());

        Artisan::call('migrate', ['--force' => true]);
        $this->assertSame(
            ['ACM00001', 'ACMMA001', 'WEBCOPT1'],
            Company::query()->orderBy('code')->pluck('code')->all(),
        );
    }

    public function test_the_seeded_company_is_webcopt1(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame('WEBCOPT1', Company::where('name', 'Webmaster Consultancy Pte Ltd')->firstOrFail()->code);
    }
}
