<?php

// Mirrors backend/app/routers/monitoring.py.

use App\Http\Controllers\Api\MonitoringController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('monitoring')->group(function () {
    Route::get('/support', [MonitoringController::class, 'support']);
});
