<?php

namespace App\Services;

use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Outbound WhatsApp messages, for the "WhatsApp OTP as a second login
 * factor" item in docs/planned-work.md -- a second, optional login-code
 * channel alongside Mailer's existing email OTP. Deliberately mirrors
 * Mailer's shape (isConfigured() / send() / a *NotConfiguredException
 * that callers can catch to fail open rather than stranding a user):
 * same contract, so AuthController treats both channels the same way.
 *
 * UNLIKE Mailer, this is not yet backed by real credentials anywhere --
 * planned-work.md is explicit that this is "blocked" until a WhatsApp
 * Business API account (Twilio's WhatsApp API or Meta's Cloud API) is
 * provisioned. This class is the option to flip on once that happens:
 * isConfigured() is false with nothing in .env, so the login flow
 * behaves exactly as it does today (email only, see AuthController's
 * availableOtpChannels()) until real credentials are set.
 *
 * Twilio first, not Meta: Twilio's WhatsApp API is reachable with a
 * single REST call (Basic Auth, no separate app-review step to start
 * sending, and a free sandbox number for testing before any real
 * WhatsApp Business Profile is approved) -- the faster path to
 * actually exercising this end to end once an account exists. Meta's
 * Cloud API is a plausible second provider later; isConfigured()/send()
 * are the only two methods AuthController calls, so adding one is a
 * self-contained change here, not a change to the login flow.
 */
class WhatsAppSender
{
    public static function isConfigured(): bool
    {
        return (bool) (config('websoft.twilio_account_sid')
            && config('websoft.twilio_auth_token')
            && config('websoft.twilio_whatsapp_from'));
    }

    /**
     * Send a WhatsApp text message. $toPhone is E.164 (e.g.
     * +6591234567, see the users.phone migration) -- the "whatsapp:"
     * prefix Twilio's API needs is added here, not stored on the user.
     */
    public static function send(string $toPhone, string $body): void
    {
        if (! self::isConfigured()) {
            throw new WhatsAppNotConfiguredException(
                'WhatsApp sending is not configured yet. Add TWILIO_ACCOUNT_SID / '
                .'TWILIO_AUTH_TOKEN / TWILIO_WHATSAPP_FROM to backend-php/.env once '
                .'a WhatsApp Business API account is provisioned.'
            );
        }

        $sid = config('websoft.twilio_account_sid');

        try {
            // Bounded the same way Mailer bounds its SMTP connect timeout:
            // an unreachable/slow Twilio should not hold the login request
            // (and the user waiting on their OTP) open indefinitely.
            $response = Http::asForm()
                ->withBasicAuth($sid, config('websoft.twilio_auth_token'))
                ->timeout(15)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'From' => 'whatsapp:'.config('websoft.twilio_whatsapp_from'),
                    'To' => 'whatsapp:'.$toPhone,
                    'Body' => $body,
                ]);
        } catch (ConnectionException|GuzzleException $e) {
            // DNS failure, connection refused, blocked egress, a proxy
            // rejecting the CONNECT tunnel outright, timeout -- never
            // reached Twilio with a real HTTP exchange, so there is no
            // Response to inspect below. Laravel's HTTP client only
            // converts Guzzle's narrower ConnectException into its own
            // ConnectionException; a blocked-egress proxy (confirmed
            // 2026-09-16 against this system's own sandboxed egress
            // proxy) surfaces as a plain GuzzleException instead, so
            // both are caught here rather than just one. Same
            // "could not send" contract as a failed response below;
            // callers (AuthController) catch WhatsAppException either
            // way to fail the login open rather than 500 it.
            throw new WhatsAppException("Could not reach Twilio: {$e->getMessage()}", previous: $e);
        }

        if ($response->failed()) {
            $detail = $response->json('message') ?? $response->body();
            throw new WhatsAppException("Could not send WhatsApp message: {$detail}");
        }
    }
}

class WhatsAppNotConfiguredException extends \RuntimeException {}
class WhatsAppException extends \RuntimeException {}
