<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\PortalUser;
use App\Services\Jwt;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer Helpdesk Portal bearer-token authentication (PORTAL-001..004,
 * docs/customer-portal-design.md §4). Mirrors
 * backend/app/core/deps.py's get_current_portal_user exactly.
 *
 * THIS IS THE WHOLE SECURITY BOUNDARY between staff and customers, and
 * it is deliberately a *separate* middleware from
 * App\Http\Middleware\Authenticate rather than a relaxed mode of it:
 *
 * - A portal token carries purpose="portal". `Authenticate` only ever
 *   accepts purpose="access" (Jwt::decodeAccessToken), so a portal
 *   token presented to any staff endpoint fails there.
 * - This middleware only ever accepts purpose="portal"
 *   (Jwt::decodePurposeToken requires an exact match), so a staff
 *   access token -- or either of the short-lived "portal_otp" /
 *   "password_change" intermediate tokens -- presented here fails too.
 * - The two realms also resolve their subject against different
 *   tables (`users` vs `portal_users`), so even an id collision could
 *   not cross over.
 *
 * Tested explicitly in both directions -- see
 * docs/customer-portal-design.md §9.4 and tests/Feature/PortalAuthTest.php.
 */
class AuthenticatePortal
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $payload = $token ? Jwt::decodePurposeToken($token, 'portal') : null;
        $portalUser = $payload ? PortalUser::with('contact.customer')->find($payload['sub']) : null;

        if ($portalUser === null || ! $portalUser->is_active) {
            throw new ApiException(401, 'Could not validate credentials', ['WWW-Authenticate' => 'Bearer']);
        }
        // A customer archived after this token was issued must lose
        // access immediately, not just on their next login (PORTAL-004).
        if ($portalUser->contact === null || $portalUser->contact->customer === null) {
            throw new ApiException(401, 'Could not validate credentials', ['WWW-Authenticate' => 'Bearer']);
        }
        if ($portalUser->contact->customer->is_archived) {
            throw new ApiException(401, 'Could not validate credentials', ['WWW-Authenticate' => 'Bearer']);
        }

        $request->attributes->set('current_portal_user', $portalUser);

        return $next($request);
    }

    public static function portalUser(Request $request): PortalUser
    {
        return $request->attributes->get('current_portal_user');
    }
}
