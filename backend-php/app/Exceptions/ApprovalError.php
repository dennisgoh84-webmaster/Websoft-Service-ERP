<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An approval rule was violated. Mirrors
 * backend/app/services/approvals.py's ApprovalError; the controller
 * turns it into a 422, the same status the Python router returns.
 */
class ApprovalError extends RuntimeException {}
