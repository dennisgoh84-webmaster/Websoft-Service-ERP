<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Services\Authority;
use Illuminate\Http\Request;

/**
 * Bank Portal Testing (docs/backlog.md "Bank Portal / ZSOFT HP Agency" --
 * still needs Dennis to say what this actually is: an in-app record +
 * Send button, vs literal automation of a real bank's website). This is
 * only the module-gated placeholder Dennis asked for so the feature can
 * be switched on for testing without any user seeing it first --
 * gated on the `bank_portal_testing` module key, same
 * Authority::requireModuleAccess() mechanism every other module uses
 * (missing/disabled CompanyModule row = not enabled = fails closed).
 * No bank integration exists yet; that is real, unscoped future work.
 */
class BankPortalController extends Controller
{
    public const MODULE = 'bank_portal_testing';

    public function status(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return response()->json([
            'enabled' => true,
            'message' => 'Bank Portal Testing is switched on for this company. No bank integration is built yet -- '
                .'see docs/backlog.md ("Bank Portal / ZSOFT HP Agency") for the open scope questions.',
        ]);
    }
}
