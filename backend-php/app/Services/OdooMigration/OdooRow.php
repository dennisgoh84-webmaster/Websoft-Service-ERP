<?php

namespace App\Services\OdooMigration;

/**
 * One spreadsheet row, keyed by normalised header (lower case, single
 * spaces), so a column can be looked up by Odoo's technical field name
 * (an "import-compatible" export: `partner_id/id`) or by its label (a
 * plain export: "Customer") interchangeably.
 */
class OdooRow
{
    /** @param  array<string, string>  $values */
    public function __construct(public int $number, public array $values) {}

    public static function normaliseHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);

        return strtolower(trim(preg_replace('/\s+/', ' ', $header)));
    }

    /** The first non-blank value among these column names, trimmed. */
    public function get(string ...$columns): ?string
    {
        foreach ($columns as $column) {
            $value = $this->values[self::normaliseHeader($column)] ?? null;
            if ($value !== null && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    public function has(string ...$columns): bool
    {
        foreach ($columns as $column) {
            if (array_key_exists(self::normaliseHeader($column), $this->values)) {
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
