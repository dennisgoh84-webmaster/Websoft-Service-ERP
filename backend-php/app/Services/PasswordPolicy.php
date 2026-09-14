<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Password hashing + complexity policy. Mirrors
 * backend/app/services/auth.py's hash_password/verify_password/
 * validate_password_complexity.
 */
class PasswordPolicy
{
    // Confirmed 2026-09-12: "staff password have to use complex password
    // like alphanumeric" -- at least one letter and one digit, on top of
    // the 8-character minimum. No uppercase/special-character rule was
    // given, so none is assumed.
    public const MIN_LENGTH = 8;

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    public static function verify(string $plainPassword, string $hashedPassword): bool
    {
        return password_verify($plainPassword, $hashedPassword);
    }

    /**
     * Throws InvalidArgumentException (caught by callers and turned
     * into a 422) if the password doesn't meet policy. Called from
     * every place a password is set: user creation, admin reset,
     * self-service change.
     */
    public static function validateComplexity(string $password): void
    {
        if (strlen($password) < self::MIN_LENGTH) {
            throw new InvalidArgumentException(sprintf('Password must be at least %d characters.', self::MIN_LENGTH));
        }
        if (! preg_match('/[A-Za-z]/', $password)) {
            throw new InvalidArgumentException('Password must contain at least one letter.');
        }
        if (! preg_match('/[0-9]/', $password)) {
            throw new InvalidArgumentException('Password must contain at least one number.');
        }
    }
}
