<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UpgradeService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UpgradeManagerController extends Controller
{
    private UpgradeService $upgradeService;

    public function __construct(UpgradeService $upgradeService)
    {
        $this->upgradeService = $upgradeService;
    }

    /**
     * Handle upgrade requests from Central Command
     * Expects: { action: 'upgrade'|'rollback', target_version?: string, backup_id?: string }
     */
    public function manager(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'action' => 'required|in:upgrade,rollback',
                'target_version' => 'required_if:action,upgrade|string|max:50',
                'backup_id' => 'string|uuid|nullable',
                'from_version' => 'string|max:50|nullable',
                'to_version' => 'string|max:50|nullable',
            ]);

            if ($validated['action'] === 'upgrade') {
                return $this->handleUpgrade($validated['target_version'], $validated['backup_id'] ?? null);
            } else {
                return $this->handleRollback(
                    $validated['from_version'] ?? null,
                    $validated['to_version'] ?? null,
                    $validated['backup_id'] ?? null
                );
            }

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    private function handleUpgrade(string $targetVersion, ?string $backupId): JsonResponse
    {
        try {
            $result = $this->upgradeService->executeUpgrade($targetVersion, $backupId);

            if (! $result['success']) {
                return response()->json($result, 400);
            }

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'version' => $result['version'],
                'release_notes' => $result['release_notes'] ?? null,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Upgrade failed: '.$e->getMessage(),
            ], 500);
        }
    }

    private function handleRollback(?string $fromVersion, ?string $toVersion, ?string $backupId): JsonResponse
    {
        try {
            $result = $this->upgradeService->executeRollback($fromVersion, $toVersion, $backupId);

            if (! $result['success']) {
                return response()->json($result, 400);
            }

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'version' => $result['version'],
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Rollback failed: '.$e->getMessage(),
            ], 500);
        }
    }
}
