<?php

namespace App\Http\Middleware;

use App\Services\Audit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Captures who/what made this request (client IP, browser User-Agent,
 * and the frontend's persisted per-browser device id -- see
 * frontend/src/lib/deviceId.ts) so App\Services\Audit::record() can
 * stamp every audit entry written during this request. Mirrors the
 * audit_request_context_middleware in backend/app/main.py.
 */
class CaptureAuditRequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        Audit::setRequestContext(
            $request->ip(),
            $request->userAgent(),
            $request->header('X-Device-Id'),
        );

        return $next($request);
    }
}
