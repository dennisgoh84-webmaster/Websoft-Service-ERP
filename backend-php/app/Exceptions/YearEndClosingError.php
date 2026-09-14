<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A Year-End Closing precondition wasn't met. Mirrors
 * backend/app/services/periods.py's YearEndClosingError.
 */
class YearEndClosingError extends RuntimeException {}
