<?php

namespace App\Services;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT as FirebaseJwt;
use Firebase\JWT\Key;
use UnexpectedValueException;

/**
 * JWT issuance/verification. Mirrors backend/app/services/auth.py's
 * token functions exactly, "purpose" claim and all: every token this
 * app issues carries a "purpose" claim ("access" for a normal API
 * bearer token, "password_change"/"otp" for the two short-lived
 * intermediate tokens the login sequence hands back), so an
 * intermediate token can never be replayed against a protected
 * endpoint even if it leaked.
 */
class Jwt
{
    public static function createAccessToken(string $userId): string
    {
        $expireMinutes = (int) config('websoft.access_token_expire_minutes');
        $payload = [
            'sub' => $userId,
            'exp' => time() + $expireMinutes * 60,
            'purpose' => 'access',
        ];

        return FirebaseJwt::encode($payload, config('websoft.jwt_secret_key'), config('websoft.jwt_algorithm'));
    }

    /** Returns the user id (a UUID string) if the token is a valid, unexpired access token; null otherwise. */
    public static function decodeAccessToken(string $token): ?string
    {
        $payload = self::decode($token);
        if ($payload === null || ($payload['purpose'] ?? null) !== 'access' || empty($payload['sub'])) {
            return null;
        }

        return $payload['sub'];
    }

    /**
     * A short-lived token for one specific next step (finishing a
     * forced password change, or completing an OTP challenge) -- never
     * accepted as an access token since its purpose isn't "access".
     *
     * @param  array<string, mixed>  $extra
     */
    public static function createPurposeToken(string $userId, string $purpose, int $expireMinutes, array $extra = []): string
    {
        $payload = array_merge([
            'sub' => $userId,
            'exp' => time() + $expireMinutes * 60,
            'purpose' => $purpose,
        ], $extra);

        return FirebaseJwt::encode($payload, config('websoft.jwt_secret_key'), config('websoft.jwt_algorithm'));
    }

    /**
     * Returns the token's payload (including `sub`) if valid and
     * matching the expected purpose; null otherwise.
     *
     * @return array<string, mixed>|null
     */
    public static function decodePurposeToken(string $token, string $expectedPurpose): ?array
    {
        $payload = self::decode($token);
        if ($payload === null || ($payload['purpose'] ?? null) !== $expectedPurpose || empty($payload['sub'])) {
            return null;
        }

        return $payload;
    }

    /** @return array<string, mixed>|null */
    private static function decode(string $token): ?array
    {
        try {
            $decoded = FirebaseJwt::decode(
                $token,
                new Key(config('websoft.jwt_secret_key'), config('websoft.jwt_algorithm')),
            );

            return (array) $decoded;
        } catch (ExpiredException|UnexpectedValueException) {
            return null;
        }
    }
}
