<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\GroupModuleAuthority;
use App\Services\Authority;
use App\Services\Monitoring;
use Illuminate\Http\Request;

/**
 * Support Monitoring. Mirrors backend/app/routers/monitoring.py 1:1 --
 * a single read-only endpoint over App\Services\Monitoring, gated on
 * the `reporting` module at VIEW level.
 */
class MonitoringController extends Controller
{
    private const MODULE = 'reporting';

    public function support(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::VIEW);

        return response()->json(Monitoring::getSupportMonitoring($user->company_id));
    }
}
