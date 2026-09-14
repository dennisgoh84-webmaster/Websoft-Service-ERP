<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a stock movement would violate a confirmed INV rule or
 * the document state machine -- e.g. deducting more than a warehouse
 * holds, confirming a document that is not in draft, or approving a
 * Stock Adjustment that was never submitted (INV-001).
 *
 * Mirrors the plain `ValueError`s raised in
 * backend/app/services/inventory.py, which backend/app/routers/stock.py
 * catches and turns into a 400. The stock controllers here do the same,
 * so the wire behaviour is identical.
 */
class InventoryRuleViolation extends RuntimeException {}
