<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Jwt;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff bearer-token authentication. Mirrors
 * backend/app/core/deps.py's get_current_user: decodes the
 * Authorization header as a purpose="access" JWT, loads the User, and
 * rejects an inactive account. Sets the resolved user on the request
 * (retrieved in controllers via `$request->user` or the `user()`
 * helper below) rather than Laravel's guard system, since this system
 * has no server-side session -- every request stands alone.
 */
class Authenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $userId = $token ? Jwt::decodeAccessToken($token) : null;
        $user = $userId ? User::find($userId) : null;

        if ($user === null || ! $user->is_active) {
            throw new ApiException(401, 'Could not validate credentials', ['WWW-Authenticate' => 'Bearer']);
        }

        $request->attributes->set('current_user', $user);

        return $next($request);
    }

    public static function user(Request $request): User
    {
        return $request->attributes->get('current_user');
    }
}
