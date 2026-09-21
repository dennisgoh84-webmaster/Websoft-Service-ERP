<?php

namespace Tests\Feature;

use App\Models\AppVersion;
use App\Models\Company;
use App\Models\User;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * App version visibility (2026-09-25) -- public endpoints so a customer
 * can check what version they're running when reporting an issue to
 * Software Support, plus an admin-only history/changelog.
 */
class AppVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_version_is_public_and_needs_no_auth(): void
    {
        AppVersion::create([
            'version' => '2.1.0', 'release_date' => now(), 'changelog' => 'Fixed things.', 'is_public' => true,
        ]);

        $this->getJson('/api/app/version')->assertOk()->assertJson(['version' => '2.1.0']);
    }

    public function test_current_version_falls_back_when_none_recorded(): void
    {
        $this->getJson('/api/app/version')->assertOk()->assertJsonStructure(['version', 'release_date']);
    }

    public function test_private_versions_are_excluded_from_public_endpoints(): void
    {
        AppVersion::create(['version' => '1.0.0', 'release_date' => now()->subDays(2), 'is_public' => true]);
        AppVersion::create(['version' => '9.9.9-internal', 'release_date' => now(), 'is_public' => false]);

        $response = $this->getJson('/api/app/version-history')->assertOk();
        $versions = collect($response->json('versions'))->pluck('version');
        $this->assertContains('1.0.0', $versions);
        $this->assertNotContains('9.9.9-internal', $versions);
    }

    public function test_creating_a_version_requires_core_administration_full(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->for($company)->create([
            'role' => User::ROLE_OWNER, 'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $login = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234']);
        $token = $login->json('access_token');

        $this->postJson('/api/app/versions', [
            'version' => '3.0.0', 'release_date' => now()->toDateString(), 'changelog' => 'Big release.',
        ], ['Authorization' => "Bearer {$token}"])->assertOk()->assertJson(['version' => '3.0.0']);

        $this->assertDatabaseHas('app_versions', ['version' => '3.0.0']);
    }

    public function test_creating_a_version_is_rejected_without_auth(): void
    {
        $this->postJson('/api/app/versions', ['version' => '3.0.0', 'release_date' => now()->toDateString()])
            ->assertStatus(401);
    }
}
