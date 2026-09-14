<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A file upload/download rule was broken. Mirrors
 * backend/app/services/documents.py's DocumentFileError -- caught by
 * App\Http\Controllers\Api\DocumentController and turned into a 422,
 * the same status the Python router raises.
 */
class DocumentFileError extends RuntimeException {}
