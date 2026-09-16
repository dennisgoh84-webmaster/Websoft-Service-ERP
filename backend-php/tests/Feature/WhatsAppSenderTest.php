<?php

namespace Tests\Feature;

use App\Services\WhatsAppException;
use App\Services\WhatsAppNotConfiguredException;
use App\Services\WhatsAppSender;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * App\Services\WhatsAppSender -- no real Twilio account exists (see
 * docs/planned-work.md #7), so every assertion here goes through
 * Http::fake() rather than a live call. Mirrors MailerTest.php's shape:
 * isConfigured() gating, then the actual request this makes once
 * configured.
 */
class WhatsAppSenderTest extends TestCase
{
    private function configure(): void
    {
        config([
            'websoft.twilio_account_sid' => 'ACtest1234',
            'websoft.twilio_auth_token' => 'test-token',
            'websoft.twilio_whatsapp_from' => '+14155238886',
        ]);
    }

    public function test_is_configured_needs_all_three_twilio_settings(): void
    {
        config(['websoft.twilio_account_sid' => null, 'websoft.twilio_auth_token' => null, 'websoft.twilio_whatsapp_from' => null]);
        $this->assertFalse(WhatsAppSender::isConfigured());

        config(['websoft.twilio_account_sid' => 'ACtest1234']);
        $this->assertFalse(WhatsAppSender::isConfigured());

        $this->configure();
        $this->assertTrue(WhatsAppSender::isConfigured());
    }

    public function test_send_without_credentials_fails_loudly_rather_than_pretending(): void
    {
        config(['websoft.twilio_account_sid' => null, 'websoft.twilio_auth_token' => null, 'websoft.twilio_whatsapp_from' => null]);

        $this->expectException(WhatsAppNotConfiguredException::class);
        WhatsAppSender::send('+6591234567', 'test');
    }

    public function test_send_posts_to_twilio_with_the_whatsapp_prefix_and_basic_auth(): void
    {
        $this->configure();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SMxxxx'], 201)]);

        WhatsAppSender::send('+6591234567', 'Your code is 123456.');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/ACtest1234/Messages.json'
                && $request['From'] === 'whatsapp:+14155238886'
                && $request['To'] === 'whatsapp:+6591234567'
                && $request['Body'] === 'Your code is 123456.'
                && $request->hasHeader('Authorization');
        });
    }

    public function test_a_failed_twilio_response_throws_with_its_error_detail(): void
    {
        $this->configure();
        Http::fake(['api.twilio.com/*' => Http::response(['message' => 'The number is not a valid WhatsApp number'], 400)]);

        $this->expectException(WhatsAppException::class);
        $this->expectExceptionMessage('The number is not a valid WhatsApp number');
        WhatsAppSender::send('+6591234567', 'test');
    }

    /**
     * BUG FIX (found 2026-09-16 exercising the login channel picker in a
     * real browser against a sandboxed egress proxy that blocks
     * api.twilio.com outright): a network-level failure -- DNS, refused,
     * blocked egress, timeout -- never produces an HTTP response to check
     * with $response->failed(); Http::post() throws ConnectionException
     * directly instead, which this test pins is caught and translated
     * into the same WhatsAppException a failed response gives. Without
     * this, AuthController::sendLoginOtp()'s catch block (which only
     * lists WhatsAppException/WhatsAppNotConfiguredException) would miss
     * it, and a Twilio outage would 500 the login page instead of the
     * intended "fail open" behaviour.
     */
    public function test_a_network_level_failure_also_throws_whatsapp_exception_rather_than_leaking_uncaught(): void
    {
        $this->configure();
        Http::fake(['api.twilio.com/*' => Http::failedConnection('Connection refused')]);

        $this->expectException(WhatsAppException::class);
        WhatsAppSender::send('+6591234567', 'test');
    }
}
