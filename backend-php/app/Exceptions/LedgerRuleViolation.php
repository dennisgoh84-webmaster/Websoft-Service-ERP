<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A ledger rule was broken -- surfaced as a 422, not a crash. Mirrors
 * backend/app/services/ledger.py's LedgerRuleViolation.
 */
class LedgerRuleViolation extends RuntimeException {}
