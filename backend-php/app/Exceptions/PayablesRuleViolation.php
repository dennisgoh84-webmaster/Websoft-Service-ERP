<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when an action would violate a confirmed PUR rule. Mirrors
 * backend/app/services/payables.py's PayablesRuleViolation -- caught
 * by App\Http\Controllers\Api\PurchaseOrderController /
 * SupplierInvoiceController and turned into a 422, same as the Python
 * router does.
 */
class PayablesRuleViolation extends RuntimeException {}
