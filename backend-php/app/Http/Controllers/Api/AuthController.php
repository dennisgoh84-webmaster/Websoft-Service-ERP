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
        ]);
    }

    /**
     * The final step of every successful credential/password/OTP check:
     * hand back a real access token, unless email OTP is configured, in
     * which case issue a challenge instead.
     */
    private function issueLoginResult(User $user): array
    {
        if (Mailer::isConfigured()) {
            $code = self::generateCode();
            LoginOtp::create([
                'user_id' => $user->id,
                'code_hash' => self::hashOtp($code),
                'purpose' => 'login',
                'expires_at' => Carbon::now('UTC')->addMinutes(self::OTP_EXPIRE_MINUTES),
            ]);
            try {
                Mailer::send(
                    $user->email,
                    'Your Websoft Service ERP login code',
                    "Your one-time login code is {$code}.\n\n".
                    'It expires in '.self::OTP_EXPIRE_MINUTES." minutes. If you didn't just try to ".
                    'sign in, you can ignore this email.'
                );
            } catch (MailerNotConfiguredException|MailerException) {
                // SMTP looked configured but the actual send failed --
                // fail OPEN rather than stranding every user outside a
                // login page they can't get past.
                return ['status' => 'ok', 'access_token' => Jwt::createAccessToken($user->id), 'token_type' => 'bearer'];
            }

            return ['status' => 'otp_required', 'otp_token' => Jwt::createPurposeToken($user->id, 'otp', self::OTP_EXPIRE_MINUTES)];
        }

        return ['status' => 'ok', 'access_token' => Jwt::createAccessToken($user->id), 'token_type' => 'bearer'];
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
