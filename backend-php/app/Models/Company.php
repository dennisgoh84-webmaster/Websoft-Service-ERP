<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A legal entity using the system, managed from Company Setup
 * (App\Http\Controllers\Api\CompanyController). Webmaster Consultancy
 * Pte Ltd is the first; CLAUDE.md's approved architecture anticipates
 * more, so every company-owned record (customers, contracts, job
 * orders, groups, users, audit entries) carries a `company_id` and is
 * filtered by the signed-in user's *active* company.
 *
 * Mirrors backend/app/models/core.py's Company.
 */
class Company extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    /**
     * System-generated company code, in the form Dennis gave 2026-09-15
     * ("3221"): the first THREE letters of the name's first word, the
     * first TWO of the second, the first TWO of the third, then a
     * running number from 1 -- "Webmaster Consultancy Pte Ltd" is
     * WEBCOPT1. Letters only, upper-cased. The number is zero-padded so
     * the whole code is CODE_LENGTH (8) characters: a shorter name gives
     * a shorter prefix and a longer number ("Acme Manufacturing" ->
     * ACMMA001, "Acme" -> ACM00001), Dennis's clarification of the same
     * day. The number runs per prefix, so a second company whose name
     * yields WEBCOPT becomes WEBCOPT2. Assigned here on create, deliberately
     * NOT fillable, so it is never typed in or changed -- a stable
     * short identifier for the entity where a UUID is unwieldy and a
     * name can be edited (renaming a company does not change its code).
     */
    protected static function booted(): void
    {
        static::creating(function (Company $company) {
            if (empty($company->code)) {
                $company->code = self::nextCode((string) $company->name);
            }
        });
    }

    /** Every code is padded to this many characters (WEBCOPT1, ACMMA001, ACM00001). */
    public const CODE_LENGTH = 8;

    /** The letters part of a code for this name: 3 + 2 + 2 from its first three words. */
    public static function codePrefix(string $name): string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];
        $parts = [];
        foreach ([3, 2, 2] as $i => $take) {
            $word = preg_replace('/[^A-Za-z]/', '', $words[$i] ?? '');
            if ($word !== '') {
                $parts[] = strtoupper(substr($word, 0, $take));
            }
        }
        $prefix = implode('', $parts);

        // A name with no letters at all still needs a code.
        return $prefix !== '' ? $prefix : 'CO';
    }

    public static function nextCode(string $name): string
    {
        $prefix = self::codePrefix($name);
        $max = 0;
        foreach (self::query()->where('code', 'like', $prefix.'%')->pluck('code') as $code) {
            if (preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', (string) $code, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return self::formatCode($prefix, $max + 1);
    }

    /**
     * Prefix + number, the number zero-padded to fill CODE_LENGTH. A
     * prefix already that long, or a number that overflows the room
     * left, simply runs on (WEBCOPT10) rather than being refused.
     */
    public static function formatCode(string $prefix, int $number): string
    {
        return $prefix.str_pad((string) $number, max(1, self::CODE_LENGTH - strlen($prefix)), '0', STR_PAD_LEFT);
    }

    protected $fillable = [
        'name', 'country', 'currency', 'timezone', 'logo', 'address',
        'gst_registration_no', 'phone', 'website', 'uen',
        'is_active',
        // Financial year + this company's own outbound mailbox
        // (2026-09-15) -- see the migration for why the company mailbox
        // is separate from the system one with no fallback.
        'financial_year_start_month',
        'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password',
        'smtp_use_tls', 'smtp_from_email', 'smtp_from_name',
    ];

    /**
     * NEVER serialised. Company Setup returns the model directly, so
     * without this the SMTP password would be handed back on every
     * read. It is write-only: set it, never read it back.
     */
    protected $hidden = ['smtp_password'];

    /**
     * Because the password itself is hidden, Company Setup would
     * otherwise have no way to show whether one is on file -- so the
     * fact of it (never the value) rides along on every read.
     */
    protected $appends = ['smtp_password_set'];

    public function getSmtpPasswordSetAttribute(): bool
    {
        return (bool) $this->smtp_password;
    }

    // Money fields: the Postgres column is `numeric(12,2)` (set in the
    // migration) so on-disk storage is always exact -- never a float.
    // These three are stored config values only (never computed on),
    // so -- like Python's CompanyOut schema, which types them as plain
    // `float | None` -- they're cast straight to float here too, so
    // the JSON wire format matches (a bare number, not a numeric
    // string). A field a *service* computes with (rates, totals,
    // allocations) instead uses 'decimal:2' + App\Support\Money -- see
    // docs/php-conversion-plan.md's Decimal/money handling convention.
    protected $attributes = ['financial_year_start_month' => 7];

    protected $casts = [
        'is_active' => 'boolean',
        'financial_year_start_month' => 'integer',
        'smtp_port' => 'integer',
        'smtp_use_tls' => 'boolean',
        // Encrypted at rest: a stolen database dump does not hand over
        // the mail account.
        'smtp_password' => 'encrypted',
        'created_at' => 'datetime',
    ];
}
