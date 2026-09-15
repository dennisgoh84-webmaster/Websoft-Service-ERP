<?php

namespace App\Services\Ai;

/**
 * Decision 12.1 (PDPA / data residency): personal data is masked before
 * any text leaves the system for the model provider, unless the owner
 * turns redaction off under Maintenance -> AI Assistant. What is
 * masked: email addresses, telephone numbers, and any names the caller
 * hands in (sender names, contact persons). Company names are not
 * personal data and are kept, since the whole point of triage is to
 * match the incident to a customer. Masking is by placeholder so the
 * model still sees THAT there was an email or a name.
 */
class Redactor
{
    /** @param  array<int, string>  $names  personal names to mask wherever they appear */
    public static function redact(?string $text, array $names = []): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }
        $out = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[email]', $text) ?? $text;
        // Telephone numbers: 7+ digits allowing spaces, dashes, brackets and a leading +.
        $out = preg_replace('/(?<![A-Za-z0-9])\+?\(?\d[\d\s\-()]{5,}\d(?![A-Za-z0-9])/', '[phone]', $out) ?? $out;
        foreach ($names as $name) {
            $name = trim((string) $name);
            if (strlen($name) < 2) {
                continue;
            }
            $out = preg_replace('/'.preg_quote($name, '/').'/iu', '[name]', $out) ?? $out;
        }

        return $out;
    }

    /** The domain part of an email -- kept even under redaction, since acme.com is the company, not the person. */
    public static function domainOf(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return null;
        }

        return strtolower(substr($email, strrpos($email, '@') + 1));
    }
}
