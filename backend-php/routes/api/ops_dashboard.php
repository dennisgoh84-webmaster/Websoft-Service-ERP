<?php

// Mirrors backend/app/routers/ops_dashboard.py.

use App\Http\Controllers\Api\OpsDashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('ops-dashboard')->group(function () {
    Route::get('', [OpsDashboardController::class, 'show']);
    Route::post('/categories', [OpsDashboardController::class, 'createCategory']);
    Route::post('/tasks', [OpsDashboardController::class, 'createTask']);
    Route::patch('/tasks/{taskId}', [OpsDashboardController::class, 'updateTask']);
    Route::post('/tasks/{taskId}/archive', [OpsDashboardController::class, 'archiveTask']);
});
