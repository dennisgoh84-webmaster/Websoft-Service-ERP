<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\AuthenticatePortal;
use App\Http\Middleware\CaptureAuditRequestContext;
use App\Http\Middleware\RequireModuleAccess;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            HandleCors::class,
            // Captures who/what made this request (IP, User-Agent, the
            // frontend's per-browser device id) so Audit::record() can
            // stamp every audit entry written during it -- mirrors the
            // audit_request_context_middleware in backend/app/main.py.
            CaptureAuditRequestContext::class,
        ]);
        // 'auth.portal' is the Customer Helpdesk Portal's own, entirely
        // separate bearer scheme (purpose="portal", resolved against
        // `portal_users`) -- see App\Http\Middleware\AuthenticatePortal
        // and backend/app/core/deps.py's get_current_portal_user. It is
        // never a relaxed mode of 'auth.jwt': neither realm's token is
        // accepted by the other's middleware.
        $middleware->alias([
            'auth.jwt' => Authenticate::class,
            'auth.portal' => AuthenticatePortal::class,
            'module' => RequireModuleAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Mirrors FastAPI's default JSON error body shape ({"detail": ...})
        // so the existing React frontend's error handling keeps working
        // unchanged against either backend.
        $exceptions->shouldRenderJsonWhen(fn () => true);
        $exceptions->render(function (ApiException $e) {
            return response()->json(['detail' => $e->getMessage()], $e->getStatusCode(), $e->getHeaders());
        });
        $exceptions->render(function (AuthenticationException $e) {
            return response()->json(['detail' => 'Could not validate credentials'], 401);
        });
        $exceptions->render(function (ValidationException $e) {
            return response()->json(['detail' => $e->errors()], 422);
        });
    })->create();
