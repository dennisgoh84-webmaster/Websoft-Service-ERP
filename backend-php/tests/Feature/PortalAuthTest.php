<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Contact;
use App\Models\PortalUser;
use App\Models\User;
use App\Services\Jwt;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customer Helpdesk Portal auth (PORTAL-001..004,
 * docs/customer-portal-design.md §4 + §9 test plan items 2, 4, 5, 6).
 *
 * The §9.4 boundary tests are the most important ones in this file: a
 * portal token must never be accepted by a staff endpoint, and a staff
 * token must never be accepted by a portal endpoint. See
 * App\Http\Middleware\AuthenticatePortal's docblock.
 */
class PortalAuthTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: PortalUser, 1: CompanyIndividual, 2: Contact} */
    private function makePortalUser(Company $company, string $password = 'portal123', array $overrides = []): array
    {
        $customer = CompanyIndividual::factory()->for($company)->create(['pdpa_consent_given' => true]);
        $contact = Contact::create([
            'customer_id' => $customer->id,
            'name' => 'Alice Tan',
            'email' => fake()->unique()->safeEmail(),
        ]);
        $portalUser = PortalUser::create(array_merge([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'hashed_password' => PasswordPolicy::hash($password),
            'must_change_password' => false,
        ], $overrides));

        return [$portalUser, $customer, $contact];
    }

    private function portalToken(PortalUser $portalUser, string $password = 'portal123'): string
    {
        // SMTP is unconfigured under `php artisan test`, so login
        // fails open and hands back the real token straight away --
        // the same behaviour staff login has, deliberately.
        $login = $this->postJson('/api/portal/auth/login', ['email' => $portalUser->email, 'password' => $password]);
        $login->assertOk()->assertJson(['status' => 'ok']);

        return $login->json('portal_token');
    }

    private function staffToken(Company $company): string
    {
        $owner = User::factory()->for($company)->create([
            'role' => User::ROLE_OWNER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $login = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234']);

        return $login->json('access_token');
    }

    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    // ---- §9.2 login -> me ------------------------------------------------

    public function test_login_returns_a_portal_token_and_me_shows_the_right_customer(): void
    {
        $company = Company::factory()->create();
        [$portalUser, $customer, $contact] = $this->makePortalUser($company);

        $me = $this->getJson('/api/portal/me', $this->headers($this->portalToken($portalUser)));

        $me->assertOk()->assertJson([
            'contact_name' => $contact->name,
            'email' => $portalUser->email,
            'customer_name' => $customer->name,
            'must_change_password' => false,
        ]);
    }

    public function test_login_reports_must_change_password_on_an_invited_account(): void
    {
        $company = Company::factory()->create();
        [$portalUser] = $this->makePortalUser($company, overrides: ['must_change_password' => true]);

        $this->postJson('/api/portal/auth/login', ['email' => $portalUser->email, 'password' => 'portal123'])
            ->assertOk()
            ->assertJson(['status' => 'ok', 'must_change_password' => true]);
    }

    public function test_wrong_password_is_a_generic_401(): void
    {
        $company = Company::factory()->create();
        [$portalUser] = $this->makePortalUser($company);

        $this->postJson('/api/portal/auth/login', ['email' => $portalUser->email, 'password' => 'nope1234'])
            ->assertStatus(401)
            ->assertJson(['detail' => 'Incorrect email or password']);
    }

    public function test_unknown_email_is_the_same_generic_401(): void
    {
        $this->postJson('/api/portal/auth/login', ['email' => 'nobody@example.com', 'password' => 'whatever1'])
            ->assertStatus(401)
            ->assertJson(['detail' => 'Incorrect email or password']);
    }

    // ---- §9.3 change password -------------------------------------------

    public function test_change_password_clears_must_change_password_and_audits_it(): void
    {
        $company = Company::factory()->create();
        [$portalUser] = $this->makePortalUser($company, overrides: ['must_change_password' => true]);
        $token = $this->portalToken($portalUser);

        $this->postJson('/api/portal/auth/change-password', ['new_password' => 'brandnew1'], $this->headers($token))
            ->assertOk()
            ->assertJson(['message' => 'Password updated.']);

        $this->assertFalse($portalUser->fresh()->must_change_password);
        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'portal_user',
            'entity_id' => $portalUser->id,
            'action' => 'password_changed_self',
            'actor_user_id' => null,
            'actor_name' => 'Alice Tan (portal)',
            'company_id' => $company->id,
        ]);
        // The new password works, the old one no longer does.
        $this->postJson('/api/portal/auth/login', ['email' => $portalUser->email, 'password' => 'brandnew1'])->assertOk();
        $this->postJson('/api/portal/auth/login', ['email' => $portalUser->email, 'password' => 'portal123'])->assertStatus(401);
    }

    public function test_change_password_rejects_a_password_failing_the_staff_complexity_policy(): void
    {
        $company = Company::factory()->create();
        [$portalUser] = $this->makePortalUser($company);
        $token = $this->portalToken($portalUser);

        // 8+ characters but no digit -- same policy as staff
        // (App\Services\PasswordPolicy), not a looser portal one.
        $this->postJson('/api/portal/auth/change-password', ['new_password' => 'lettersonly'], $this->headers($token))
            ->assertStatus(422)
            ->assertJson(['detail' => 'Password must contain at least one number.']);
    }

    public function test_forgot_password_always_returns_the_same_generic_message(): void
    {
        $company = Company::factory()->create();
        [$portalUser] = $this->makePortalUser($company);
        $generic = 'If an account exists for that email, a one-time code has been sent to it.';

        $this->postJson('/api/portal/auth/forgot-password', ['email' => $portalUser->email])
            ->assertOk()->assertJson(['message' => $generic]);
        $this->postJson('/api/portal/auth/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()->assertJson(['message' => $generic]);
    }

    public function test_reset_password_with_a_bogus_code_is_a_generic_401(): void
    {
        $company = Company::factory()->create();
        [$portalUser] = $this->makePortalUser($company);

        $this->postJson('/api/portal/auth/reset-password-otp', [
            'email' => $portalUser->email, 'code' => '000000', 'new_password' => 'brandnew1',
        ])->assertStatus(401)->assertJson(['detail' => 'Incorrect or expired code.']);
    }

    // ---- §9.5 disabled / archived ----------------------------------------

    public function test_a_disabled_portal_user_cannot_log_in_and_a_live_token_stops_working(): void
    {
        $company = Company::factory()->create();
        [$portalUser] = $this->makePortalUser($company);
        $token = $this->portalToken($portalUser);
        $this->getJson('/api/portal/me', $this->headers($token))->assertOk();

        $portalUser->is_active = false;
        $portalUser->save();

        $this->getJson('/api/portal/me', $this->headers($token))->assertStatus(401);
        $this->postJson('/api/portal/auth/login', ['email' => $portalUser->email, 'password' => 'portal123'])
            ->assertStatus(401);
    }

    public function test_archiving_the_customer_stops_a_live_portal_token_immediately(): void
    {
        $company = Company::factory()->create();
        [$portalUser, $customer] = $this->makePortalUser($company);
        $token = $this->portalToken($portalUser);
        $this->getJson('/api/portal/me', $this->headers($token))->assertOk();

        $customer->is_archived = true;
        $customer->save();

        // PORTAL-004: not lazily on next login -- immediately.
        $this->getJson('/api/portal/me', $this->headers($token))->assertStatus(401);
        $this->postJson('/api/portal/auth/login', ['email' => $portalUser->email, 'password' => 'portal123'])
            ->assertStatus(401);
    }

    // ---- §9.6 lockout ----------------------------------------------------

    public function test_five_wrong_passwords_locks_the_login_for_fifteen_minutes(): void
    {
        $company = Company::factory()->create();
        [$portalUser] = $this->makePortalUser($company);

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/portal/auth/login', ['email' => $portalUser->email, 'password' => 'wrong123'])
                ->assertStatus(401)->assertJson(['detail' => 'Incorrect email or password']);
        }
        $this->assertSame(4, $portalUser->fresh()->failed_attempts);

        // The 5th failure trips the lock (and resets the counter, same
        // as the Python source).
        $this->postJson('/api/portal/auth/login', ['email' => $portalUser->email, 'password' => 'wrong123'])
            ->assertStatus(401);
        $locked = $portalUser->fresh();
        $this->assertSame(0, $locked->failed_attempts);
        $this->assertNotNull($locked->locked_until);
        $this->assertEqualsWithDelta(15 * 60, now()->diffInSeconds($locked->locked_until), 60);

        // Even the CORRECT password is refused while locked, with the
        // lockout message rather than the generic one.
        $this->postJson('/api/portal/auth/login', ['email' => $portalUser->email, 'password' => 'portal123'])
            ->assertStatus(401)
            ->assertJsonPath('detail', fn ($d) => str_contains($d, 'Too many incorrect attempts'));
    }

    public function test_a_successful_login_clears_the_failure_counter(): void
    {
        $company = Company::factory()->create();
        [$portalUser] = $this->makePortalUser($company);

        $this->postJson('/api/portal/auth/login', ['email' => $portalUser->email, 'password' => 'wrong123'])->assertStatus(401);
        $this->assertSame(1, $portalUser->fresh()->failed_attempts);

        $this->postJson('/api/portal/auth/login', ['email' => $portalUser->email, 'password' => 'portal123'])->assertOk();
        $this->assertSame(0, $portalUser->fresh()->failed_attempts);
    }

    // ---- §9.4 THE SECURITY BOUNDARY --------------------------------------

    public function test_a_portal_token_is_refused_by_staff_endpoints(): void
    {
        $company = Company::factory()->create();
        [$portalUser] = $this->makePortalUser($company);
        $portalHeaders = $this->headers($this->portalToken($portalUser));

        foreach ([
            '/api/auth/me',
            '/api/company-individuals',
            '/api/contracts',
            '/api/job-orders',
            '/api/service-records',
            '/api/invoices',
            '/api/incidents',
        ] as $staffRoute) {
            $this->getJson($staffRoute, $portalHeaders)
                ->assertStatus(401, "portal token must not be accepted by {$staffRoute}");
        }
    }

    public function test_a_staff_token_is_refused_by_portal_endpoints(): void
    {
        $company = Company::factory()->create();
        $this->makePortalUser($company);
        $staffHeaders = $this->headers($this->staffToken($company));

        $this->getJson('/api/portal/me', $staffHeaders)->assertStatus(401);
        $this->postJson('/api/portal/auth/change-password', ['new_password' => 'brandnew1'], $staffHeaders)
            ->assertStatus(401);
    }

    public function test_the_short_lived_otp_token_is_not_accepted_as_a_portal_session_token(): void
    {
        $company = Company::factory()->create();
        [$portalUser] = $this->makePortalUser($company);

        // An intermediate purpose="portal_otp" token must never work as
        // a session token, the same rule staff's "otp"/"password_change"
        // tokens follow.
        $otpToken = Jwt::createPurposeToken($portalUser->id, 'portal_otp', 10);
        $this->getJson('/api/portal/me', $this->headers($otpToken))->assertStatus(401);
    }

    public function test_a_portal_token_signed_for_a_staff_user_id_resolves_to_nothing(): void
    {
        $company = Company::factory()->create();
        $this->makePortalUser($company);
        $staffUser = User::factory()->for($company)->create();

        // Even a correctly-signed purpose="portal" token whose subject
        // is a staff user id finds no PortalUser row -- the two realms
        // resolve against different tables.
        $forged = Jwt::createPurposeToken($staffUser->id, 'portal', 60);
        $this->getJson('/api/portal/me', $this->headers($forged))->assertStatus(401);
    }

    public function test_portal_endpoints_require_a_token_at_all(): void
    {
        $this->getJson('/api/portal/me')->assertStatus(401);
    }
}
