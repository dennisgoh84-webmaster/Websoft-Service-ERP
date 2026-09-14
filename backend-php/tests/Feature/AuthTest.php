<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mirrors the parts of backend/app/routers/auth.py's login sequence
 * that don't depend on SMTP being configured (must_change_password ->
 * ok; unconfigured SMTP means no OTP step -- see
 * App\Services\Mailer::isConfigured()).
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_correct_credentials_returns_access_token(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create([
            'hashed_password' => PasswordPolicy::hash('demo1234'),
            'must_change_password' => false,
        ]);

        $response = $this->post('/api/auth/login', [
            'username' => $user->email,
            'password' => 'demo1234',
        ]);

        $response->assertOk()->assertJson(['status' => 'ok']);
        $this->assertNotEmpty($response->json('access_token'));
    }

    public function test_login_with_wrong_password_is_rejected(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create([
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);

        $response = $this->post('/api/auth/login', [
            'username' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
    }

    public function test_first_login_requires_password_change(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create([
            'hashed_password' => PasswordPolicy::hash('demo1234'),
            'must_change_password' => true,
        ]);

        $response = $this->post('/api/auth/login', [
            'username' => $user->email,
            'password' => 'demo1234',
        ]);

        $response->assertOk()->assertJson(['status' => 'must_change_password']);
        $this->assertNotEmpty($response->json('change_token'));
        $this->assertArrayNotHasKey('access_token', $response->json());
    }

    public function test_me_requires_a_valid_bearer_token(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_me_returns_current_user_after_login(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create([
            'hashed_password' => PasswordPolicy::hash('demo1234'),
            'must_change_password' => false,
            'role' => User::ROLE_OWNER,
        ]);

        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);
        $token = $login->json('access_token');

        $response = $this->getJson('/api/auth/me', ['Authorization' => "Bearer {$token}"]);

        $response->assertOk()->assertJson(['email' => $user->email, 'role' => 'owner']);
    }
}
