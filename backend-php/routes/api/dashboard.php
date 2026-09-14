<?php

// Mirrors backend/app/routers/dashboard.py. Authenticated-only, with
// no Module Control gate -- the Python router has none either; see
// DashboardController's class docblock.

use App\Http\Controllers\Api\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('dashboard')->group(function () {
    Route::get('/summary', [DashboardController::class, 'summary']);
});
