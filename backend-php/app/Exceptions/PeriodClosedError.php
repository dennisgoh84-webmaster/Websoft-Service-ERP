<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A transaction date falls inside a period that has this operation
 * locked. Mirrors backend/app/services/periods.py's PeriodClosedError.
 */
class PeriodClosedError extends RuntimeException {}
