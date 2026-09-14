<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when an action would violate a confirmed AR rule. Mirrors
 * backend/app/services/accounts_receivable.py's ARRuleViolation --
 * caught by App\Http\Controllers\Api\AccountsReceivableController and
 * turned into a 422, same as the Python router does. A distinct class
 * from App\Exceptions\ContractRuleViolation because the Python source
 * keeps them distinct too (unlike Service Records/Excess Usage, which
 * both reuse ContractRuleViolation directly from contracts.py).
 */
class ARRuleViolation extends RuntimeException {}
