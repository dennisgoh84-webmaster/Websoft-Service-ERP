<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\LoginOtp;
use App\Models\User;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\Jwt;
use App\Services\Mailer;
use App\Services\MailerException;
use App\Services\MailerNotConfiguredException;
use App\Services\PasswordPolicy;
use App\Services\WhatsAppException;
use App\Services\WhatsAppNotConfiguredException;
use App\Services\WhatsAppSender;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Login sequence. Mirrors backend/app/routers/auth.py exactly -- see
 * that file's module docstring for the full step-by-step client
 * sequence (must_change_password -> otp_required -> ok) and the
 * separate "forgot password" pair.
 */
class AuthController extends Controller
{
    private const OTP_EXPIRE_MINUTES = 10;

    private const OTP_MAX_ATTEMPTS = 5;

    private const CHANGE_PASSWORD_TOKEN_EXPIRE_MINUTES = 15;

    private const FORGOT_PASSWORD_GENERIC_MESSAGE = 'If an account exists for that email, a one-time code has been sent to it.';

    public function login(Request $request)
    {
        $data = $request->validate(['username' => 'required|string', 'password' => 'required|string']);

        $user = User::where('email', $data['username'])->first();
        if (! $user || ! PasswordPolicy::verify($data['password'], $user->hashed_password)) {
            throw new ApiException(401, 'Incorrect email or password');
        }
        if (! $user->is_active) {
            throw new ApiException(401, 'This account has been deactivated.');
        }

        if ($user->must_change_password) {
            return response()->json([
                'status' => 'must_change_password',
                'change_token' => Jwt::createPurposeToken($user->id, 'password_change', self::CHANGE_PASSWORD_TOKEN_EXPIRE_MINUTES),
            ]);
        }

        return response()->json($this->issueLoginResult($user));
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate(['change_token' => 'required|string', 'new_password' => 'required|string|min:8']);

        $payload = Jwt::decodePurposeToken($data['change_token'], 'password_change');
        if ($payload === null) {
            throw new ApiException(401, 'This session has expired -- please sign in again.');
        }
        $user = User::find($payload['sub']);
        if (! $user || ! $user->is_active) {
            throw new ApiException(401, 'Account not found or inactive.');
        }

        try {
            PasswordPolicy::validateComplexity($data['new_password']);
        } catch (InvalidArgumentException $e) {
            throw new ApiException(422, $e->getMessage());
        }
        if (PasswordPolicy::verify($data['new_password'], $user->hashed_password)) {
            throw new ApiException(422, 'New password must be different from your current password.');
        }

        $user->hashed_password = PasswordPolicy::hash($data['new_password']);
        $user->must_change_password = false;
        Audit::record('user', $user->id, 'password_changed_self', $user->id);
        $user->save();

        return response()->json($this->issueLoginResult($user));
    }

    public function verifyOtp(Request $request)
    {
        $data = $request->validate(['otp_token' => 'required|string', 'code' => 'required|string|size:6']);

        $payload = Jwt::decodePurposeToken($data['otp_token'], 'otp');
        if ($payload === null) {
            throw new ApiException(401, 'This code has expired -- please sign in again to get a new one.');
        }
        $user = User::find($payload['sub']);
        if (! $user || ! $user->is_active) {
            throw new ApiException(401, 'Account not found or inactive.');
        }

        $otp = LoginOtp::where('user_id', $user->id)
            ->where('purpose', 'login')
            ->whereNull('consumed_at')
            ->orderByDesc('created_at')
            ->first();

        $now = Carbon::now('UTC');
        if (! $otp || $otp->expires_at->lt($now)) {
            throw new ApiException(401, 'This code has expired -- please sign in again to get a new one.');
        }
        if ($otp->attempts >= self::OTP_MAX_ATTEMPTS) {
            throw new ApiException(401, 'Too many incorrect attempts -- please sign in again to get a new code.');
        }
        if (self::hashOtp($data['code']) !== $otp->code_hash) {
            $otp->increment('attempts');
            throw new ApiException(401, 'Incorrect code.');
        }

        $otp->consumed_at = $now;
        $otp->save();

        return response()->json(['status' => 'ok', 'access_token' => Jwt::createAccessToken($user->id), 'token_type' => 'bearer']);
    }

    /**
     * The second half of the two-step login-factor choice: called after
     * issueLoginResult() found BOTH email and WhatsApp available and
     * returned `otp_channel_required` instead of sending anything yet.
     * Sends on whichever channel the user picked and returns the same
     * `otp_required` shape login() already returns when there is only
     * one channel -- verifyOtp() doesn't need to know which path led here.
     */
    public function sendOtp(Request $request)
    {
        $data = $request->validate(['channel_token' => 'required|string', 'channel' => 'required|in:email,whatsapp']);

        $payload = Jwt::decodePurposeToken($data['channel_token'], 'otp_channel');
        if ($payload === null) {
            throw new ApiException(401, 'This session has expired -- please sign in again.');
        }
        $user = User::find($payload['sub']);
        if (! $user || ! $user->is_active) {
            throw new ApiException(401, 'Account not found or inactive.');
        }

        // Re-check rather than trust the client's earlier choice -- the
        // phone could have been cleared, or WhatsApp un-configured,
        // between the channel-choice screen rendering and this call.
        if (! in_array($data['channel'], $this->availableOtpChannels($user), true)) {
            throw new ApiException(400, 'That channel is not available for this account.');
        }

        return response()->json($this->sendLoginOtp($user, $data['channel']));
    }

    public function forgotPassword(Request $request)
    {
        $data = $request->validate(['email' => 'required|string']);

        $user = User::where('email', $data['email'])->where('is_active', true)->first();
        if ($user && Mailer::isConfigured()) {
            $code = self::generateCode();
            $otp = LoginOtp::create([
                'user_id' => $user->id,
                'code_hash' => self::hashOtp($code),
                'purpose' => 'password_reset',
                'expires_at' => Carbon::now('UTC')->addMinutes(self::OTP_EXPIRE_MINUTES),
            ]);
            try {
                Mailer::send(
                    $user->email,
                    'Reset your Websoft Service ERP password',
                    "Your one-time password-reset code is {$code}.\n\n".
                    'It expires in '.self::OTP_EXPIRE_MINUTES." minutes. If you didn't request a ".
                    "password reset, you can ignore this email -- your password hasn't changed."
                );
            } catch (MailerNotConfiguredException|MailerException) {
                // still return the generic message below -- never reveal send failures
            }
        }

        // Always the same response, regardless of whether the account
        // exists, is active, or SMTP is even configured.
        return response()->json(['message' => self::FORGOT_PASSWORD_GENERIC_MESSAGE]);
    }

    public function resetPasswordWithOtp(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|string',
            'code' => 'required|string|size:6',
            'new_password' => 'required|string|min:8',
        ]);

        $genericError = fn () => throw new ApiException(401, 'Incorrect or expired code.');

        $user = User::where('email', $data['email'])->first();
        if (! $user || ! $user->is_active) {
            $genericError();
        }

        $otp = LoginOtp::where('user_id', $user->id)
            ->where('purpose', 'password_reset')
            ->whereNull('consumed_at')
            ->orderByDesc('created_at')
            ->first();

        $now = Carbon::now('UTC');
        if (! $otp || $otp->expires_at->lt($now) || $otp->attempts >= self::OTP_MAX_ATTEMPTS) {
            $genericError();
        }
        if (self::hashOtp($data['code']) !== $otp->code_hash) {
            $otp->increment('attempts');
            $genericError();
        }

        try {
            PasswordPolicy::validateComplexity($data['new_password']);
        } catch (InvalidArgumentException $e) {
            throw new ApiException(422, $e->getMessage());
        }

        $otp->consumed_at = $now;
        $otp->save();
        $user->hashed_password = PasswordPolicy::hash($data['new_password']);
        // They just proved they control the account's email and chose
        // this password themselves -- no need to force yet another change.
        $user->must_change_password = false;
        Audit::record('user', $user->id, 'password_reset_via_forgot_password', $user->id);
        $user->save();

        return response()->json(['message' => 'Password updated. You can now sign in with your new password.']);
    }

    public function me(Request $request)
    {
        $user = Authenticate::user($request);

        return response()->json([
            'id' => $user->id,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'role' => $user->role,
            'group_id' => Authority::getUserGroupId($user),
            'company_id' => $user->company_id,
            'ai_data_consent_required' => $user->needsAiDataConsent(),
        ]);
    }

    /**
     * PDPA self-declaration for the AI Assistant (Dennis, 2026-09-15):
     * every staff user must acknowledge, once, that their queries may
     * be sent to Anthropic's US-hosted API (masked by default) and
     * that non-sensitive usage data may be analysed internally, before
     * they can use the system. The frontend blocks on
     * `ai_data_consent_required` (from login/me) until this succeeds.
     *
     * Deliberately a one-way door: if `ai_data_consent_at` is already
     * set, this call is a no-op that just returns the existing
     * timestamp -- it can NEVER be moved, cleared or re-dated, by this
     * endpoint or any other (see User::$casts's docblock and
     * UserController::update()'s validated field list).
     */
    public function acknowledgeAiConsent(Request $request)
    {
        $user = Authenticate::user($request);
        $data = $request->validate(['accepted' => 'required|accepted']);

        if ($user->ai_data_consent_at === null) {
            // Kept in a local variable and used below rather than
            // re-read from $user: Eloquent's datetime cast round-trips
            // a freshly-assigned Carbon through a timezone-less
            // "Y-m-d H:i:s" string on the way into $attributes, so
            // reading the attribute straight back BEFORE any DB
            // round-trip re-parses that naive string in the app's
            // LOCAL timezone (Asia/Singapore) rather than UTC -- an
            // 8-hour corruption of exactly the figure this PDPA record
            // most needs to be right. The value actually written to
            // the database is unaffected (Postgres receives and
            // returns it correctly, confirmed against a raw query),
            // and every subsequent read -- Staff Master included --
            // re-fetches from the database and is therefore correct;
            // only THIS response, right after the fact, was ever wrong.
            $now = Carbon::now('UTC');
            $user->ai_data_consent_at = $now;
            $user->save();
            Audit::record(
                'user', $user->id, 'ai_data_consent_acknowledged', $user->id,
                details: 'AI Assistant PDPA notice acknowledged at login',
            );

            return response()->json([
                'ai_data_consent_at' => $now->toJSON(),
                'ai_data_consent_required' => false,
            ]);
        }

        return response()->json([
            'ai_data_consent_at' => $user->ai_data_consent_at->toJSON(),
            'ai_data_consent_required' => false,
        ]);
    }

    /**
     * The final step of every successful credential/password/OTP check.
     * Three cases, by how many OTP channels are available for this user
     * (see availableOtpChannels()):
     *
     *   0 -> hand back a real access token directly (unchanged from
     *        before WhatsApp OTP existed -- e.g. no SMTP configured).
     *   1 -> send on that one channel immediately and issue a challenge,
     *        exactly like the email-only version of this method always
     *        did (now also reachable via WhatsApp alone, e.g. a user
     *        with a phone on file at an install that has WhatsApp but
     *        not SMTP configured).
     *   2 -> BOTH available: don't send anything yet. Return
     *        `otp_channel_required` and let the user pick -- see
     *        sendOtp(), which does the actual sending once they have.
     *        This is the "let the user choose email or WhatsApp at the
     *        OTP step" behaviour docs/planned-work.md asks for.
     */
    private function issueLoginResult(User $user): array
    {
        $channels = $this->availableOtpChannels($user);

        if (count($channels) === 0) {
            return $this->grantAccess($user);
        }
        if (count($channels) === 1) {
            return $this->sendLoginOtp($user, $channels[0]);
        }

        return [
            'status' => 'otp_channel_required',
            'channel_token' => Jwt::createPurposeToken($user->id, 'otp_channel', self::OTP_EXPIRE_MINUTES),
            'available_channels' => $channels,
        ];
    }

    /** Which OTP channels this specific user could receive a code on right now. */
    private function availableOtpChannels(User $user): array
    {
        $channels = [];
        if (Mailer::isConfigured()) {
            $channels[] = 'email';
        }
        // WhatsApp additionally needs a phone on file for THIS user --
        // being configured install-wide isn't enough, unlike email
        // (one shared mailbox already addresses everyone by definition).
        if (WhatsAppSender::isConfigured() && $user->phone) {
            $channels[] = 'whatsapp';
        }

        return $channels;
    }

    private function grantAccess(User $user): array
    {
        return ['status' => 'ok', 'access_token' => Jwt::createAccessToken($user->id), 'token_type' => 'bearer', 'ai_data_consent_required' => $user->needsAiDataConsent()];
    }

    /** Create the OTP row and actually send it on the given channel. */
    private function sendLoginOtp(User $user, string $channel): array
    {
        $code = self::generateCode();
        LoginOtp::create([
            'user_id' => $user->id,
            'code_hash' => self::hashOtp($code),
            'purpose' => 'login',
            'channel' => $channel,
            'expires_at' => Carbon::now('UTC')->addMinutes(self::OTP_EXPIRE_MINUTES),
        ]);

        try {
            if ($channel === 'whatsapp') {
                WhatsAppSender::send(
                    $user->phone,
                    "Your Websoft Service ERP login code is {$code}. It expires in ".self::OTP_EXPIRE_MINUTES.' minutes.'
                );
            } else {
                Mailer::send(
                    $user->email,
                    'Your Websoft Service ERP login code',
                    "Your one-time login code is {$code}.\n\n".
                    'It expires in '.self::OTP_EXPIRE_MINUTES." minutes. If you didn't just try to ".
                    'sign in, you can ignore this email.'
                );
            }
        } catch (MailerNotConfiguredException|MailerException|WhatsAppNotConfiguredException|WhatsAppException) {
            // Looked configured but the actual send failed -- fail OPEN
            // rather than stranding every user outside a login page they
            // can't get past. Same contract issueLoginResult() always
            // had for email; now shared by both channels.
            return $this->grantAccess($user);
        }

        return [
            'status' => 'otp_required',
            'otp_token' => Jwt::createPurposeToken($user->id, 'otp', self::OTP_EXPIRE_MINUTES),
            // So the frontend can say "we texted/emailed you" accurately
            // instead of guessing -- harmless extra field for any client
            // that predates WhatsApp OTP and doesn't look at it.
            'channel' => $channel,
        ];
    }

    private static function hashOtp(string $code): string
    {
        return hash('sha256', $code);
    }

    private static function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
