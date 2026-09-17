<?php

use App\Http\Controllers\Api\UpgradeManagerController;
use Illuminate\Support\Facades\Route;

/*
 * System administration endpoints
 * These require admin authentication and handle system-level operations
 */

Route::middleware(['api', 'auth:sanctum', 'admin'])->prefix('admin/system')->group(function () {
    // Upgrade manager endpoint - called by Central Command to upgrade/rollback
    Route::post('/upgrade-manager', [UpgradeManagerController::class, 'manager']);
});
