<?php

namespace App\Services;

use App\Exceptions\CurrencyRuleViolation;
use App\Models\CompanyIndividual;
use App\Models\CurrencyRate;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Multi-currency (Dennis, 2026-09-26, decision page; #49 "Full
 * multi-currency: sales and purchases in any currency, keeping the
 * original and SGD amounts, with exchange gain/loss").
 *
 * - A document is in one currency. Its figures are kept twice: in that
 *   currency (the *_fx columns) and in SGD (the *_sgd columns every
 *   report, the General Ledger and the GST return already read). An SGD
 *   document has rate 1 and the same figure in both.
 * - The currency defaults from the Company / Individual's own default
 *   currency, and can be changed on the document.
 * - The rate is the Currency Rate Table's latest active rate on or
 *   before the document date ("1 unit = X SGD"), and can be changed on
 *   the document.
 * - Exchange gain or loss is booked only when paid (realised), when a
 *   receipt or payment is allocated -- see ExchangeDifference.
 */
class Currency
{
    public const BASE = 'SGD';

    /** The currency code, tidied: upper case, SGD when blank. */
    public static function code(?string $code): string
    {
        $code = strtoupper(trim((string) $code));

        return $code === '' ? self::BASE : $code;
    }

    public static function isBase(?string $code): bool
    {
        return self::code($code) === self::BASE;
    }

    /** The latest active rate for a currency on or before a date, if the table has one. */
    public static function rateOn(string $companyId, string $code, Carbon|string $date): ?string
    {
        if (self::isBase($code)) {
            return '1.000000';
        }
        $rate = CurrencyRate::where('company_id', $companyId)->where('currency_code', self::code($code))
            ->where('is_active', true)->whereDate('effective_date', '<=', Carbon::parse($date)->toDateString())
            ->orderByDesc('effective_date')->value('rate_to_base');

        return $rate !== null ? (string) $rate : null;
    }

    /** A party's default currency: its own, else SGD. */
    public static function defaultFor(?string $companyIndividualId): string
    {
        return self::code($companyIndividualId ? CompanyIndividual::whereKey($companyIndividualId)->value('default_currency') : null);
    }

    /**
     * The currency and rate a document is raised in: the one keyed (else
     * the party's default), at the rate keyed (else the table's).
     *
     * @return array{0: string, 1: string} [code, rate]
     *
     * @throws CurrencyRuleViolation when a foreign currency has no rate keyed and none in the table
     */
    public static function resolve(string $companyId, ?string $code, string|int|float|null $rate, Carbon|string $date, ?string $partyId = null): array
    {
        $code = $code !== null && trim($code) !== '' ? self::code($code) : self::defaultFor($partyId);
        if (! preg_match('/^[A-Z]{3}$/', $code)) {
            throw new CurrencyRuleViolation("\"{$code}\" is not a currency code (three letters, e.g. USD).");
        }
        if (self::isBase($code)) {
            return [self::BASE, '1.000000'];
        }
        if ($rate !== null && $rate !== '') {
            if (! is_numeric($rate) || (float) $rate <= 0) {
                throw new CurrencyRuleViolation('The exchange rate must be a number greater than zero.');
            }

            return [$code, number_format((float) $rate, 6, '.', '')];
        }
        $fromTable = self::rateOn($companyId, $code, $date);
        if ($fromTable === null) {
            throw new CurrencyRuleViolation(sprintf(
                'No %s rate on or before %s in the Currency Rate Table -- add one there, or key the rate on this document.',
                $code, Carbon::parse($date)->format('d/m/Y'),
            ));
        }

        return [$code, $fromTable];
    }

    /** A document-currency amount in SGD at the document's rate, to the cent (half up). */
    public static function toSgd(Money|string|int|float $amount, string|float $rate): Money
    {
        $amount = $amount instanceof Money ? $amount : Money::of($amount);

        return $amount->multipliedBy((string) $rate)->quantize();
    }
}
