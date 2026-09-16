<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\LoginOtp;
use App\Models\User;
use App\Services\Mailer;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Mirrors the parts of backend/app/routers/auth.py's login sequence
 * that don't depend on SMTP being configured (must_change_password ->
 * ok; unconfigured SMTP means no OTP step -- see
 * App\Services\Mailer::isConfigured()), plus the WhatsApp OTP channel
 * choice this PHP backend adds on top (docs/planned-work.md #7):
 * AuthController::issueLoginResult()'s three cases by channel count.
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function configureSmtp(): void
    {
        config([
            'websoft.smtp_host' => 'smtp.office365.com',
            'websoft.smtp_port' => 587,
            'websoft.smtp_username' => 'noreply@webmaster.example',
            'websoft.smtp_password' => 'p@ss word',
            'websoft.smtp_use_tls' => true,
            'websoft.smtp_from_email' => 'noreply@webmaster.example',
            'websoft.smtp_from_name' => 'Web Master Consultancy',
        ]);
        // Capture in memory rather than opening a real SMTP connection -- see Mailer::fake()'s docblock.
        Mailer::fake();
    }

    private function configureWhatsApp(): void
    {
        config([
            'websoft.twilio_account_sid' => 'ACtest1234',
            'websoft.twilio_auth_token' => 'test-token',
            'websoft.twilio_whatsapp_from' => '+14155238886',
        ]);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SMxxxx'], 201)]);
    }

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

    public function test_no_channel_configured_signs_straight_in_exactly_like_before_whatsapp_existed(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create([
            'hashed_password' => PasswordPolicy::hash('demo1234'),
            'must_change_password' => false,
            'phone' => '+6591234567',
        ]);

        $response = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $response->assertOk()->assertJson(['status' => 'ok']);
        $this->assertNotEmpty($response->json('access_token'));
    }

    public function test_only_email_configured_sends_immediately_same_as_before_whatsapp_existed(): void
    {
        $this->configureSmtp();
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create([
            'hashed_password' => PasswordPolicy::hash('demo1234'),
            'must_change_password' => false,
        ]);

        $response = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $response->assertOk()->assertJson(['status' => 'otp_required', 'channel' => 'email']);
        $this->assertNotEmpty($response->json('otp_token'));
        $this->assertSame('email', LoginOtp::where('user_id', $user->id)->latest('created_at')->first()->channel);
    }

    public function test_only_whatsapp_configured_needs_a_phone_on_file_to_be_offered(): void
    {
        $this->configureWhatsApp();
        $company = Company::factory()->create();
        $noPhone = User::factory()->for($company)->create([
            'hashed_password' => PasswordPolicy::hash('demo1234'),
            'must_change_password' => false,
            'phone' => null,
        ]);

        // No channel available at all (WhatsApp needs a phone, email isn't configured) -> straight in.
        $response = $this->post('/api/auth/login', ['username' => $noPhone->email, 'password' => 'demo1234']);
        $response->assertOk()->assertJson(['status' => 'ok']);

        $withPhone = User::factory()->for($company)->create([
            'hashed_password' => PasswordPolicy::hash('demo1234'),
            'must_change_password' => false,
            'phone' => '+6591234567',
        ]);
        $response2 = $this->post('/api/auth/login', ['username' => $withPhone->email, 'password' => 'demo1234']);
        $response2->assertOk()->assertJson(['status' => 'otp_required', 'channel' => 'whatsapp']);
        Http::assertSent(fn ($r) => $r['To'] === 'whatsapp:+6591234567');
    }

    public function test_both_channels_available_lets_the_user_choose_at_login(): void
    {
        $this->configureSmtp();
        $this->configureWhatsApp();
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create([
            'hashed_password' => PasswordPolicy::hash('demo1234'),
            'must_change_password' => false,
            'phone' => '+6591234567',
        ]);

        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);
        $login->assertOk()->assertJson(['status' => 'otp_channel_required']);
        $this->assertNotEmpty($login->json('channel_token'));
        $this->assertSame(['email', 'whatsapp'], $login->json('available_channels'));
        // Nothing sent yet -- send-otp is a separate, explicit step.
        $this->assertSame(0, LoginOtp::where('user_id', $user->id)->count());
        Http::assertNothingSent();

        $channelToken = $login->json('channel_token');
        $sendResponse = $this->postJson('/api/auth/send-otp', ['channel_token' => $channelToken, 'channel' => 'whatsapp']);
        $sendResponse->assertOk()->assertJson(['status' => 'otp_required', 'channel' => 'whatsapp']);
        Http::assertSent(fn ($r) => $r['To'] === 'whatsapp:+6591234567');
        $this->assertSame('whatsapp', LoginOtp::where('user_id', $user->id)->latest('created_at')->first()->channel);
    }

    public function test_send_otp_rejects_a_channel_that_is_not_actually_available(): void
    {
        $this->configureSmtp();
        $this->configureWhatsApp();
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create([
            'hashed_password' => PasswordPolicy::hash('demo1234'),
            'must_change_password' => false,
            'phone' => null, // WhatsApp not actually available for this user
        ]);

        // With only email available, login sends immediately -- there is
        // no channel_token to reuse, so exercise the guard directly with
        // a channel_token minted against this user the same way it would
        // be if a client tried to smuggle 'whatsapp' in anyway.
        $channelToken = \App\Services\Jwt::createPurposeToken($user->id, 'otp_channel', 10);

        $this->postJson('/api/auth/send-otp', ['channel_token' => $channelToken, 'channel' => 'whatsapp'])
            ->assertStatus(400);
    }

    public function test_a_channel_that_looks_configured_but_fails_to_send_fails_open(): void
    {
        config([
            'websoft.twilio_account_sid' => 'ACtest1234',
            'websoft.twilio_auth_token' => 'test-token',
            'websoft.twilio_whatsapp_from' => '+14155238886',
        ]);
        Http::fake(['api.twilio.com/*' => Http::response(['message' => 'not a valid WhatsApp number'], 400)]);
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create([
            'hashed_password' => PasswordPolicy::hash('demo1234'),
            'must_change_password' => false,
            'phone' => '+6591234567',
        ]);

        $response = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $response->assertOk()->assertJson(['status' => 'ok']);
        $this->assertNotEmpty($response->json('access_token'));
    }
}
