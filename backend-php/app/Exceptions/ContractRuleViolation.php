<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when an action would violate a confirmed SRV rule. Mirrors
 * backend/app/services/contracts.py's ContractRuleViolation -- caught
 * by App\Http\Controllers\Api\ContractController and turned into a
 * 422, same as the Python router does.
 */
class ContractRuleViolation extends RuntimeException {}
