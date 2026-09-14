<?php

// Mirrors backend/app/routers/excess_usage.py. NOT yet converted (see
// docs/php-conversion-plan.md): CSV/Excel export.

use App\Http\Controllers\Api\ExcessUsageController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('excess-usage')->group(function () {
    Route::get('/', [ExcessUsageController::class, 'index']);
    Route::post('/{excessUsage}/decide', [ExcessUsageController::class, 'decide']);
});
