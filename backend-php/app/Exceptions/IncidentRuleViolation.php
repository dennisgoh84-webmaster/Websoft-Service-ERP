<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when an Incident action would violate a confirmed rule (not
 * open, no customer set, an invalid contract). Mirrors
 * backend/app/services/incidents.py's IncidentRuleViolation -- caught
 * by App\Http\Controllers\Api\IncidentController and turned into a
 * 422, same as the Python router does.
 */
class IncidentRuleViolation extends RuntimeException {}
