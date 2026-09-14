<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\CompanyModule;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\User;
use App\Services\Authority;
use Illuminate\Http\Request;

/**
 * Module access check -- used by the frontend nav, and a read-only
 * catalog used by Group Authority setup. Mirrors
 * backend/app/routers/modules.py. Module management (enable/disable)
 * is out of scope here too -- it lives in the separate Central Command
 * admin portal, not this API.
 */
class ModuleController extends Controller
{
    public function myAccess(Request $request)
    {
        $user = Authenticate::user($request);

        $modules = ModuleCatalog::where('is_built', true)->get();
        $companyModules = CompanyModule::where('company_id', $user->company_id)->get()->keyBy('module_key');

        $result = [];
        foreach ($modules as $m) {
            if ($user->role === User::ROLE_OWNER) {
                // Owner bypasses both checks -- nav visibility mirrors that.
                $result[$m->key] = true;

                continue;
            }
            $cm = $companyModules->get($m->key);
            $result[$m->key] = (bool) ($cm && $cm->enabled) && Authority::hasAccess($user, $m->key, GroupModuleAuthority::VIEW);
        }

        return response()->json($result);
    }

    public function index(Request $request)
    {
        $user = Authenticate::user($request);

        $modules = ModuleCatalog::orderBy('key')->get();
        $companyModules = CompanyModule::where('company_id', $user->company_id)->get()->keyBy('module_key');

        return $modules->map(function ($m) use ($companyModules) {
            $cm = $companyModules->get($m->key);

            return [
                'key' => $m->key,
                'name' => $m->name,
                'description' => $m->description,
                'is_built' => $m->is_built,
                'enabled' => $cm?->enabled ?? false,
                'license_type' => $cm?->license_type ?? CompanyModule::INCLUDED,
            ];
        })->values();
    }
}
