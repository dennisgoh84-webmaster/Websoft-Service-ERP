<?php

namespace App\Services;

use App\Models\TaxCode;
use App\Support\Money;

/**
 * GST calculation. Mirrors backend/app/services/tax.py exactly --
 * confirmed 2026-09-10: Webmaster Consultancy is GST-registered and
 * its services are standard-rated. The rate itself is data
 * (App\Models\TaxCode) so a future rate change is a configuration
 * change, and each invoice records the rate it was actually raised
 * at.
 */
class Tax
{
    public static function getTaxCode(string $companyId, ?string $code = null): ?TaxCode
    {
        return TaxCode::where('company_id', $companyId)
            ->where('code', $code ?? TaxCode::DEFAULT_CODE)
            ->where('is_active', true)
            ->first();
    }

    /** GST on a net amount, rounded to cents (half up). */
    public static function gstFor(Money $netAmount, Money $ratePercent): Money
    {
        return $netAmount->multipliedBy($ratePercent->toFloat())->dividedBy(100)->quantize();
    }

    /**
     * Returns [taxCode, ratePercent, gstAmount, totalIncludingGst]. If no
     * tax code is configured for the company, GST is zero and the
     * invoice is raised net-only -- an unconfigured company should not
     * have tax silently invented for it.
     *
     * @return array{0: string, 1: Money, 2: Money, 3: Money}
     */
    public static function applyGst(string $companyId, Money $netAmount, ?string $code = null): array
    {
        $taxCode = self::getTaxCode($companyId, $code);
        if ($taxCode === null) {
            return [$code ?? TaxCode::DEFAULT_CODE, Money::of(0), Money::of(0), $netAmount];
        }

        $rate = Money::of($taxCode->rate_percent);
        $gst = self::gstFor($netAmount, $rate);

        return [$taxCode->code, $rate, $gst, $netAmount->plus($gst)];
    }
}
