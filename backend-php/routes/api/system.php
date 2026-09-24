<?php

// Remote-controlled upgrades. The two agent routes are called only by
// deploy/upgrade-agent.sh on this server's own host, authenticated by
// the X-Upgrade-Agent-Token header (see UpgradeAgentController), so
// they sit outside the auth.jwt group. Central Command never calls
// them -- it writes into `upgrade_requests` directly.

use App\Http\Controllers\Api\UpgradeAgentController;
use Illuminate\Support\Facades\Route;

Route::prefix('system/upgrade-agent')->group(function () {
    Route::post('/heartbeat', [UpgradeAgentController::class, 'heartbeat']);
    Route::post('/report', [UpgradeAgentController::class, 'report']);
});

Route::middleware('auth.jwt')->get('/system/upgrade/status', [UpgradeAgentController::class, 'status']);
