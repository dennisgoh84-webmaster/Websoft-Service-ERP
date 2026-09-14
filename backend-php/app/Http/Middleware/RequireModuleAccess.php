<?php

namespace App\Http\Middleware;

use App\Services\Authority;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware form of require_module_access() in
 * backend/app/services/authority.py. Usage (see routes/api/*.php):
 *
 *   Route::get('/company-individuals', ...)
 *       ->middleware(['auth.jwt', 'module:company_individual_management,view']);
 *
 * Must run after `auth.jwt` (needs the resolved current user).
 */
class RequireModuleAccess
{
    public function handle(Request $request, Closure $next, string $moduleKey, string $minLevel): Response
    {
        Authority::requireModuleAccess(Authenticate::user($request), $moduleKey, $minLevel);

        return $next($request);
    }
}
