<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Precise decimal money arithmetic -- the PHP equivalent of Python's
 * `decimal.Decimal` + `.quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)`
 * pattern used throughout backend/app/services/billing.py (e.g.
 * blended_rate_per_hour, issue_excess_usage_invoice).
 *
 * PHP has no built-in arbitrary-precision decimal type, and native
 * float arithmetic (`0.1 + 0.2`) is NOT safe for money -- it silently
 * accumulates rounding error. Never use float/double for a money
 * *computation* (a rate, a total, a proration, a tax amount). This
 * class wraps brick/math's BigDecimal so every module's money
 * arithmetic rounds the same way, in the same place, rather than each
 * service re-implementing (or forgetting) it.
 *
 * Convention (see docs/php-conversion-plan.md "Decimal/money
 * handling"):
 * - DB column: `decimal(12,2)` (matches Python's `Numeric(12, 2)`).
 * - Eloquent cast: `'amount_sgd' => 'decimal:2'` -- keeps the exact
 *   on-disk value available as a string for comparisons/persistence.
 * - Any arithmetic on a money value: go through this class, not `+`
 *   `-` `*` `/` on the raw attribute.
 * - JSON response: cast the final result to float with
 *   Money::toFloat() before returning it -- mirrors Python's
 *   `float(Decimal(...))` at the Pydantic schema boundary, so the
 *   wire format (a bare JSON number, not a numeric string) matches
 *   between the two backends.
 */
final class Money
{
    private function __construct(private readonly BigDecimal $value) {}

    /** @param string|int|float $value A DB attribute, request input, or another Money's toString(). */
    public static function of(string|int|float $value): self
    {
        return new self(BigDecimal::of((string) $value));
    }

    public function plus(self $other): self
    {
        return new self($this->value->plus($other->value));
    }

    public function minus(self $other): self
    {
        return new self($this->value->minus($other->value));
    }

    /** @param string|int|float $factor Not another Money -- a plain multiplier/divisor (e.g. hours, a rate). */
    public function multipliedBy(string|int|float $factor): self
    {
        return new self($this->value->toScale(10, RoundingMode::HALF_UP)->multipliedBy((string) $factor));
    }

    /**
     * Multiply by another Money's full internal precision directly,
     * rather than round-tripping it through toFloat()/toString()
     * first (both of which quantize to 2dp -- fine for a final
     * result, but lossy as an intermediate factor). Needed wherever
     * two computed values multiply together before the final
     * quantize(), e.g. a blended rate times a fractional hour count
     * (App\Services\BillingService::issueExcessUsageInvoice(), mirrors
     * Python's `rate * excess_hours` where both sides are full-
     * precision Decimal).
     */
    public function multipliedByMoney(self $other): self
    {
        return new self($this->value->multipliedBy($other->value));
    }

    public function dividedBy(string|int|float $divisor): self
    {
        return new self($this->value->toScale(10, RoundingMode::HALF_UP)->dividedBy((string) $divisor, 10, RoundingMode::HALF_UP));
    }

    /**
     * Rounds to 2 decimal places, half-up -- the same rounding mode
     * and precision as every `.quantize(Decimal("0.01"),
     * rounding=ROUND_HALF_UP)` call in backend/app/services/billing.py.
     * Call this once, at the point a computation is finished, not
     * after every intermediate step (matches how the Python service
     * layer only quantizes the final rate/amount, not each operand).
     */
    public function quantize(): self
    {
        return new self($this->value->toScale(2, RoundingMode::HALF_UP));
    }

    /** The exact decimal string, e.g. "1234.50" -- safe to store back into a `decimal:2` column. */
    public function toString(): string
    {
        return $this->value->toScale(2, RoundingMode::HALF_UP)->__toString();
    }

    /**
     * For the JSON response boundary only (see class docstring) --
     * never for further arithmetic or for a comparison against
     * another money value (float equality is unreliable).
     */
    public function toFloat(): float
    {
        return (float) $this->toString();
    }
}
