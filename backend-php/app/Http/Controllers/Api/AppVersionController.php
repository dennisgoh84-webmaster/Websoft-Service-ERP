<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\AppVersion;
use App\Services\Authority;
use Illuminate\Http\Request;

/**
 * App version visibility for customers and staff.
 * Public endpoints (no auth) allow customers to check their app version.
 * Admin endpoints manage version history and releases.
 */
class AppVersionController extends Controller
{
    private const MODULE = 'core_administration';

    private const CURRENT_VERSION = '1.0.0';

    /**
     * Get current public app version (no auth required).
     * Customers can check what version they are running.
     */
    public function current()
    {
        $version = AppVersion::public()
            ->orderByVersionDesc()
            ->first();

        if (! $version) {
            return response()->json([
                'version' => self::CURRENT_VERSION,
                'release_date' => now(),
                'changelog' => null,
            ]);
        }

        return response()->json([
            'version' => $version->version,
            'release_date' => $version->release_date,
            'changelog' => $version->changelog,
        ]);
    }

    /**
     * Get version history (no auth required).
     * Paginated list of public releases for customer reference.
     */
    public function history(Request $request)
    {
        $limit = min($request->integer('limit', 10), 50);
        $offset = $request->integer('offset', 0);

        $versions = AppVersion::public()
            ->orderByVersionDesc()
            ->offset($offset)
            ->limit($limit)
            ->get();

        $total = AppVersion::public()->count();

        return response()->json([
            'versions' => $versions,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Create a new version entry (admin only).
     */
    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $data = $request->validate([
            'version' => 'required|string|unique:app_versions',
            'release_date' => 'required|date',
            'changelog' => 'sometimes|nullable|string',
            'deployment_notes' => 'sometimes|nullable|string',
            'is_public' => 'sometimes|boolean',
        ]);

        $version = AppVersion::create([
            'version' => $data['version'],
            'release_date' => $data['release_date'],
            'changelog' => $data['changelog'] ?? null,
            'deployment_notes' => $data['deployment_notes'] ?? null,
            'is_public' => $data['is_public'] ?? true,
        ]);

        return response()->json($version);
    }

    /**
     * Update a version entry (admin only).
     * Useful to mark a version public/private or update changelog.
     */
    public function update(Request $request, string $versionId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $version = AppVersion::findOrFail($versionId);

        $data = $request->validate([
            'changelog' => 'sometimes|nullable|string',
            'deployment_notes' => 'sometimes|nullable|string',
            'is_public' => 'sometimes|boolean',
        ]);

        $version->update($data);

        return response()->json($version);
    }
}
