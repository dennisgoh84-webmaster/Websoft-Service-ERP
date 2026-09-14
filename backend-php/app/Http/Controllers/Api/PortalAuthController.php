<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticatePortal;
use App\Models\LoginOtp;
use App\Models\PortalUser;
use App\Services\Audit;
use App\Services\Jwt;
use App\Services\Mailer;
use App\Services\MailerException;
use App\Services\MailerNotConfiguredException;
use App\Services\PasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Customer Helpdesk Portal -- customer-side auth (PORTAL-001..006).
 * Mirrors the auth half of backend/app/routers/portal.py; the data
 * endpoints of that same Python router are in
 * App\Http\Controllers\Api\PortalController (split only because this
 * codebase already keeps staff auth in its own AuthController -- the
 * routes, shapes and behaviour are identical to the single Python
 * router). Design: docs/customer-portal-design.md.
 *
 * Auth sequence (deliberately simpler than staff's -- see design §4):
 * 1. POST /api/portal/auth/login (email + password). Returns
 *    status="otp_required" (a code was just emailed) or status="ok"
 *    with the real portal_token straight away if SMTP isn't configured
 *    -- same fail-open behaviour as staff login, for the same reason
 *    (never let a missing SMTP setup lock everyone out).
 * 2. POST /api/portal/auth/verify-otp (otp_token + code). Returns
 *    status="ok" with portal_token -- a JWT carrying purpose="portal",
 *    the whole security boundary (see
 *    App\Http\Middleware\AuthenticatePortal). must_change_password
 *    comes back alongside it; the frontend routes to the
 *    change-password screen first when true, but the token itself
 *    already works for every other endpoint.
 * 3. POST /api/portal/auth/change-password (Authorization: Bearer
 *    <portal_token> + new_password). Clears must_change_password.
 *
 * "Forgot password" mirrors staff's: POST /auth/forgot-password always
 * returns the same generic message; POST /auth/reset-password-otp sets
 * a new password directly once email+code match.
 *
 * Lockout (design §4): 5 wrong passwords locks the login for 15
 * minutes, tracked on PortalUser.failed_attempts / locked_until (there
 * is no separate table -- a portal login is one person, one row).
 *
 * PYTHON QUIRK carried across deliberately: a login looks the
 * PortalUser up by email alone, with no company filter, even though
 * the uniqueness constraint is (company_id, email) -- so if the same
 * person's email were ever enabled under two different companies, the
 * first row wins. Not a security hole (the password still has to
 * match that row), but it is a fidelity-preserved quirk, not a
 * decision: see docs/php-conversion-plan.md's Portal entry.
 */
class PortalAuthController extends Controller
{
    private const OTP_EXPIRE_MINUTES = 10;

    private const OTP_MAX_ATTEMPTS = 5;

    // 12 hours -- a customer, not a staff shift.
    private const PORTAL_TOKEN_EXPIRE_MINUTES = 60 * 12;

    private const LOGIN_LOCK_THRESHOLD = 5;

    private const LOGIN_LOCK_MINUTES = 15;

    private const FORGOT_PASSWORD_GENERIC_MESSAGE = 'If an account exists for that email, a one-time code has been sent to it.';

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|string', 'password' => 'required|string']);

        $portalUser = PortalUser::with('contact.customer')->where('email', $data['email'])->first();
        $genericError = fn () => throw new ApiException(401, 'Incorrect email or password');
        if (! $portalUser || ! $portalUser->is_active) {
            $genericError();
        }

        $now = Carbon::now('UTC');
        if ($portalUser->locked_until && $portalUser->locked_until->gt($now)) {
            throw new ApiException(401, sprintf(
                'Too many incorrect attempts. This login is locked until %s (SGT server time).',
                $portalUser->locked_until->format('H:i'),
            ));
        }
        if ($portalUser->contact === null || $portalUser->contact->customer === null || $portalUser->contact->customer->is_archived) {
            $genericError();
        }

        if (! PasswordPolicy::verify($data['password'], $portalUser->hashed_password)) {
            $portalUser->failed_attempts = $portalUser->failed_attempts + 1;
            if ($portalUser->failed_attempts >= self::LOGIN_LOCK_THRESHOLD) {
                $portalUser->locked_until = $now->copy()->addMinutes(self::LOGIN_LOCK_MINUTES);
                $portalUser->failed_attempts = 0;
            }
            $portalUser->save();
            $genericError();
        }

        $portalUser->failed_attempts = 0;
        $portalUser->locked_until = null;
        $portalUser->save();

        return response()->json($this->issueLoginResult($portalUser));
    }

    public function verifyOtp(Request $request)
    {
        $data = $request->validate(['otp_token' => 'required|string', 'code' => 'required|string|size:6']);

        $payload = Jwt::decodePurposeToken($data['otp_token'], 'portal_otp');
        if ($payload === null) {
            throw new ApiException(401, 'This code has expired -- please sign in again to get a new one.');
        }
        $portalUser = PortalUser::find($payload['sub']);
        if (! $portalUser || ! $portalUser->is_active) {
            throw new ApiException(401, 'Account not found or inactive.');
        }

        $otp = LoginOtp::where('portal_user_id', $portalUser->id)
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
        $portalUser->last_login_at = $now;
        $portalUser->save();

        return response()->json([
            'status' => 'ok',
            'portal_token' => self::issuePortalToken($portalUser),
            'must_change_password' => $portalUser->must_change_password,
        ]);
    }

    public function changePassword(Request $request)
    {
        $portalUser = AuthenticatePortal::portalUser($request);
        $data = $request->validate(['new_password' => 'required|string|min:8']);

        try {
            PasswordPolicy::validateComplexity($data['new_password']);
        } catch (InvalidArgumentException $e) {
            throw new ApiException(422, $e->getMessage());
        }

        $portalUser->hashed_password = PasswordPolicy::hash($data['new_password']);
        $portalUser->must_change_password = false;
        Audit::record(
            'portal_user', $portalUser->id, 'password_changed_self', null,
            actorName: "{$portalUser->contact->name} (portal)",
            companyId: $portalUser->company_id,
        );
        $portalUser->save();

        return response()->json(['message' => 'Password updated.']);
    }

    public function forgotPassword(Request $request)
    {
        $data = $request->validate(['email' => 'required|string']);

        $portalUser = PortalUser::where('email', $data['email'])->where('is_active', true)->first();
        if ($portalUser && Mailer::isConfigured()) {
            $code = self::generateCode();
            LoginOtp::create([
                'portal_user_id' => $portalUser->id,
                'code_hash' => self::hashOtp($code),
                'purpose' => 'password_reset',
                'expires_at' => Carbon::now('UTC')->addMinutes(self::OTP_EXPIRE_MINUTES),
            ]);
            try {
                Mailer::send(
                    $portalUser->email,
                    'Reset your Websoft Helpdesk Portal password',
                    "Your one-time password-reset code is {$code}.\n\n".
                    'It expires in '.self::OTP_EXPIRE_MINUTES." minutes. If you didn't request a ".
                    "password reset, you can ignore this email -- your password hasn't changed."
                );
            } catch (MailerNotConfiguredException|MailerException) {
                // still return the generic message below -- never reveal send failures
            }
        }

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

        $portalUser = PortalUser::with('contact')->where('email', $data['email'])->first();
        if (! $portalUser || ! $portalUser->is_active) {
            $genericError();
        }

        $otp = LoginOtp::where('portal_user_id', $portalUser->id)
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
        $portalUser->hashed_password = PasswordPolicy::hash($data['new_password']);
        $portalUser->must_change_password = false;
        $portalUser->failed_attempts = 0;
        $portalUser->locked_until = null;
        Audit::record(
            'portal_user', $portalUser->id, 'password_reset_via_forgot_password', null,
            actorName: "{$portalUser->contact->name} (portal)",
            companyId: $portalUser->company_id,
        );
        $portalUser->save();

        return response()->json(['message' => 'Password updated. You can now sign in with your new password.']);
    }

    public function me(Request $request)
    {
        $portalUser = AuthenticatePortal::portalUser($request);

        return response()->json([
            'contact_name' => $portalUser->contact->name,
            'email' => $portalUser->email,
            'customer_name' => $portalUser->contact->customer->name,
            'must_change_password' => $portalUser->must_change_password,
        ]);
    }

    /**
     * Mirrors AuthController's issueLoginResult, but the portal always
     * hands back the real token here (never a further gate) --
     * must_change_password is reported alongside it for the frontend
     * to act on, per this class's docblock.
     *
     * @return array<string, mixed>
     */
    private function issueLoginResult(PortalUser $portalUser): array
    {
        if (Mailer::isConfigured()) {
            $code = self::generateCode();
            LoginOtp::create([
                'portal_user_id' => $portalUser->id,
                'code_hash' => self::hashOtp($code),
                'purpose' => 'login',
                'expires_at' => Carbon::now('UTC')->addMinutes(self::OTP_EXPIRE_MINUTES),
            ]);
            try {
                Mailer::send(
                    $portalUser->email,
                    'Your Websoft Helpdesk Portal login code',
                    "Your one-time login code is {$code}.\n\n".
                    'It expires in '.self::OTP_EXPIRE_MINUTES." minutes. If you didn't just try to ".
                    'sign in, you can ignore this email.'
                );
            } catch (MailerNotConfiguredException|MailerException) {
                // Fail open, same reasoning as staff login -- never
                // strand a customer outside the portal because SMTP
                // hiccupped.
                return [
                    'status' => 'ok',
                    'portal_token' => self::issuePortalToken($portalUser),
                    'must_change_password' => $portalUser->must_change_password,
                ];
            }

            return [
                'status' => 'otp_required',
                'otp_token' => Jwt::createPurposeToken($portalUser->id, 'portal_otp', self::OTP_EXPIRE_MINUTES),
            ];
        }

        return [
            'status' => 'ok',
            'portal_token' => self::issuePortalToken($portalUser),
            'must_change_password' => $portalUser->must_change_password,
        ];
    }

    private static function issuePortalToken(PortalUser $portalUser): string
    {
        return Jwt::createPurposeToken($portalUser->id, 'portal', self::PORTAL_TOKEN_EXPIRE_MINUTES);
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
