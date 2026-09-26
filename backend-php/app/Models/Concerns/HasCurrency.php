<?php

namespace App\Models\Concerns;

use App\Services\Currency;
use App\Support\Money;

/**
 * A document kept in its own currency as well as in SGD (multi-currency,
 * 2026-09-26; see App\Services\Currency). A document from before then,
 * or one built without the new columns (an older test factory), reads
 * as SGD at rate 1 with its SGD figures.
 */
trait HasCurrency
{
    public function currencyCode(): string
    {
        return Currency::code($this->currency_code ?? null);
    }

    public function rate(): string
    {
        return (string) ($this->exchange_rate ?? '1');
    }

    public function isForeign(): bool
    {
        return ! Currency::isBase($this->currency_code ?? null);
    }

    /** A figure in the document's own currency: its *_fx column, else (an SGD document) its *_sgd one. */
    public function fx(string $name): Money
    {
        return Money::of($this->{"{$name}_fx"} ?? $this->{"{$name}_sgd"} ?? 0);
    }
}
