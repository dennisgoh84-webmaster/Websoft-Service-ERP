<?php

namespace App\Services\DataMigration;

/**
 * One spreadsheet row. Straight out of the file it is keyed by the
 * normalised column heading; once the field mapping is applied
 * (MigrationEngine) it is keyed by this system's field keys instead,
 * which is what the importers read.
 */
class SourceRow
{
    /** @param  array<string, string>  $values */
    public function __construct(public int $number, public array $values) {}

    public static function normaliseHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);

        return strtolower(trim(preg_replace('/\s+/', ' ', $header)));
    }

    /** The first non-blank value among these keys, trimmed. */
    public function get(string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->values[self::normaliseHeader($key)] ?? null;
            if ($value !== null && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    public function has(string ...$keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists(self::normaliseHeader($key), $this->values)) {
                return true;
            }
        }

        return false;
    }

    public function isBlank(): bool
    {
        foreach ($this->values as $value) {
            if (trim($value) !== '') {
                return false;
            }
        }

        return true;
    }
}
