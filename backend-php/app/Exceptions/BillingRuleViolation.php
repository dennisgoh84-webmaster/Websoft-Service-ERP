<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when an action would violate a confirmed Billing rule --
 * caught by App\Http\Controllers\Api\InvoiceController and turned into
 * a 422, the same way ARRuleViolation and ContractRuleViolation are
 * handled by their own controllers.
 *
 * New with manually raised Sales Invoices (2026-09-15); the invoices
 * converted from `backend/` are all auto-issued from another module's
 * decision, so nothing raised one before there was a request body to
 * get wrong.
 */
class BillingRuleViolation extends RuntimeException {}
