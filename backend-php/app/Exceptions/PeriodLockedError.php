<?php

namespace App\Exceptions;

/**
 * More descriptive subclass of PeriodClosedError; same catch
 * semantics. Mirrors backend/app/services/periods.py's
 * PeriodLockedError.
 */
class PeriodLockedError extends PeriodClosedError {}
