<?php

namespace App\Exceptions;

/** A document's currency or exchange rate cannot be used (see App\Services\Currency). */
class CurrencyRuleViolation extends \RuntimeException {}
