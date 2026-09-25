<?php

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\Company;
use App\Models\LoginOtp;
use App\Models\User;
use App\Services\Jwt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Gmail add-on's sign-in (docs/outlook-addin.md): a signed-in user
 * asks for a one-time connect code, the add-on trades it for an access
 * token. Never a password through the add-on.
 */
class AddinConnectTest extends TestCase
{
    use RefreshDatabase;

    private function signedIn(): array
    {
        $user = User::factory()->for(Company::factory()->create())->create(['must_change_password' => false]);

        return [$user, ['Authorization' => 'Bearer '.Jwt::createAccessToken($user->id)]];
    }

    public function test_a_code_signs_the_add_on_in_once(): void
    {
        [$user, $auth] = $this->signedIn();

        $code = $this->postJson('/api/auth/addin-connect-code', [], $auth)->assertOk()
            ->assertJsonPath('expires_in_minutes', 10)->json('code');
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $code);

        // Typed loosely: lower case, no dash.
        $token = $this->postJson('/api/auth/addin-connect', ['code' => strtolower(str_replace('-', '', $code))])
            ->assertOk()->assertJsonPath('status', 'ok')->json('access_token');
        $this->assertSame($user->id, Jwt::decodeAccessToken($token));
        $this->getJson('/api/auth/me', ['Authorization' => "Bearer {$token}"])->assertOk();

        // One use only.
        $this->postJson('/api/auth/addin-connect', ['code' => $code])->assertStatus(401);

        $this->assertTrue(AuditLogEntry::where('entity_id', $user->id)->where('action', 'signed_in_via_addin')->exists());
    }

    public function test_the_code_is_stored_only_as_a_hash(): void
    {
        [$user, $auth] = $this->signedIn();
        $code = str_replace('-', '', $this->postJson('/api/auth/addin-connect-code', [], $auth)->json('code'));

        $row = LoginOtp::where('user_id', $user->id)->where('purpose', 'addin_connect')->sole();
        $this->assertSame(hash('sha256', $code), $row->code_hash);
    }

    public function test_asking_again_cancels_the_previous_code(): void
    {
        [, $auth] = $this->signedIn();
        $first = $this->postJson('/api/auth/addin-connect-code', [], $auth)->json('code');
        $second = $this->postJson('/api/auth/addin-connect-code', [], $auth)->json('code');

        $this->postJson('/api/auth/addin-connect', ['code' => $first])->assertStatus(401);
        $this->postJson('/api/auth/addin-connect', ['code' => $second])->assertOk();
    }

    public function test_an_expired_or_wrong_code_is_refused(): void
    {
        [, $auth] = $this->signedIn();
        $code = $this->postJson('/api/auth/addin-connect-code', [], $auth)->json('code');

        $this->postJson('/api/auth/addin-connect', ['code' => 'ABCD-EFGH'])->assertStatus(401);

        Carbon::setTestNow(Carbon::now()->addMinutes(11));
        $this->postJson('/api/auth/addin-connect', ['code' => $code])->assertStatus(401)
            ->assertJsonPath('detail', 'That code is wrong or has expired. Get a new one from Websoft and try again.');
        Carbon::setTestNow();
    }

    public function test_an_inactive_user_cannot_connect(): void
    {
        [$user, $auth] = $this->signedIn();
        $code = $this->postJson('/api/auth/addin-connect-code', [], $auth)->json('code');
        $user->update(['is_active' => false]);

        $this->postJson('/api/auth/addin-connect', ['code' => $code])->assertStatus(401);
    }

    public function test_getting_a_code_needs_a_signed_in_user(): void
    {
        $this->postJson('/api/auth/addin-connect-code')->assertStatus(401);
    }

    public function test_guessing_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/addin-connect', ['code' => 'ZZZZ-ZZZZ'])->assertStatus(401);
        }
        $this->postJson('/api/auth/addin-connect', ['code' => 'ZZZZ-ZZZZ'])->assertStatus(429);
    }
}
