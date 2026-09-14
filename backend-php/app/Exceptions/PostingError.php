<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A document could not be posted -- surfaced as a 422, not a crash.
 * Mirrors backend/app/services/posting.py's PostingError.
 */
class PostingError extends RuntimeException {}
