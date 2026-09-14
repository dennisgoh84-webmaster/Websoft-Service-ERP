<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

/**
 * Verifies App\Support\Money reproduces the exact rounding behaviour
 * of backend/app/services/billing.py's blended_rate_per_hour /
 * issue_excess_usage_invoice (Decimal + ROUND_HALF_UP, 2dp) -- this is
 * what every future money-arithmetic module (Contracts, Billing,
 * Excess Usage, AR) must match, so a regression here would silently
 * break invoice amounts.
 */
class MoneyTest extends TestCase
{
    public function test_blended_rate_matches_python_example(): void
    {
        // SRV-008 worked example from docs/business-requirements.md-style
        // usage: a SGD 3,000 / 10-hour contract has a blended rate of
        // exactly 300.00/hr.
        $contractedHours = Money::of(600)->dividedBy(60); // 600 minutes -> 10 hours
        $rate = Money::of(3000)->dividedBy($contractedHours->toString())->quantize();

        $this->assertSame('300.00', $rate->toString());
        $this->assertSame(300.0, $rate->toFloat());
    }

    public function test_rate_with_recurring_decimal_rounds_half_up_to_two_places(): void
    {
        // SGD 1,000 over 3 hours -> 333.333... -> 333.33 (not 333.34).
        $rate = Money::of(1000)->dividedBy(3)->quantize();

        $this->assertSame('333.33', $rate->toString());
    }

    public function test_exact_half_cent_rounds_up_not_to_even(): void
    {
        // Python's ROUND_HALF_UP rounds 100.005 -> 100.01 (never
        // banker's rounding to 100.00) -- PHP's own round() defaults
        // to the same half-away-from-zero behaviour for positives, but
        // this pins it explicitly for the brick/math path we actually use.
        $amount = Money::of('100.005')->quantize();

        $this->assertSame('100.01', $amount->toString());
    }

    public function test_excess_usage_amount_matches_rate_times_hours(): void
    {
        // 45 excess minutes (0.75h) at a 300.00/hr blended rate -> 225.00.
        $rate = Money::of('300.00');
        $excessHours = Money::of(45)->dividedBy(60);
        $amount = $rate->multipliedBy($excessHours->toString())->quantize();

        $this->assertSame('225.00', $amount->toString());
    }
}
