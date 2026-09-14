<?php

namespace App\Exceptions;

use Exception;

/**
 * Mirrors FastAPI's HTTPException: a status code, a plain-text detail
 * message, and optional headers (e.g. WWW-Authenticate). Caught by the
 * renderer registered in bootstrap/app.php and turned into the same
 * {"detail": "..."} JSON shape the existing React frontend already
 * expects from the Python backend.
 */
class ApiException extends Exception
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly int $statusCode,
        string $detail,
        private readonly array $headers = [],
    ) {
        parent::__construct($detail);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
