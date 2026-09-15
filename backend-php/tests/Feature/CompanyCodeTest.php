<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\ModuleCatalog;
use App\Models\User;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The system-generated company code shown on Company Setup (2026-09-15). */
class CompanyCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_codes_are_assigned_in_creation_order_and_never_typed_in(): void
    {
        $first = Company::factory()->create();
        $second = Company::factory()->create();

        $this->assertSame('C001', $first->code);
        $this->assertSame('C002', $second->code);

        // Mass assignment cannot set or change it.
        $third = Company::create(['name' => 'Attempt', 'code' => 'ZZZ']);
        $this->assertSame('C003', $third->fresh()->code);
    }

    public function test_the_code_is_returned_by_the_api_and_a_patch_cannot_change_it(): void
    {
        $company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'core_administration'], ['name' => 'Core', 'is_built' => true]);
        CompanyModule::updateOrCreate(['company_id' => $company->id, 'module_key' => 'core_administration'], ['enabled' => true]);
        $owner = User::factory()->for($company)->create(['role' => User::ROLE_OWNER, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        $token = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234'])->json('access_token');
        $h = ['Authorization' => "Bearer {$token}"];

        $this->getJson('/api/companies', $h)->assertOk()->assertJsonPath('0.code', 'C001');

        $this->patchJson("/api/companies/{$company->id}", ['code' => 'HACK', 'name' => 'Renamed'], $h)
            ->assertOk()->assertJsonPath('code', 'C001')->assertJsonPath('name', 'Renamed');

        // A company created through the screen gets the next code.
        $this->postJson('/api/companies', ['name' => 'Websoft Digital Pte Ltd'], $h)
            ->assertOk()->assertJsonPath('code', 'C002');
    }

    public function test_numbering_continues_from_the_highest_code_never_reusing_one(): void
    {
        Company::factory()->create(); // C001
        // A code set out of sequence (a migrated or renumbered entity):
        // the sequence carries on from the highest, never fills in below it.
        Company::factory()->create()->forceFill(['code' => 'C010'])->save();

        $this->assertSame('C011', Company::factory()->create()->code);
    }
}
