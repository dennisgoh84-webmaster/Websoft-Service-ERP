<?php

// Mirrors backend/app/routers/periods.py. NOT yet converted: CSV/Excel
// export (the Python router itself has none for this module).

use App\Http\Controllers\Api\PeriodController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('accounting-periods')->group(function () {
    Route::get('/', [PeriodController::class, 'index']);
    Route::post('/', [PeriodController::class, 'store']);
    Route::get('/closures', [PeriodController::class, 'listClosures']);
    Route::post('/close-fiscal-year', [PeriodController::class, 'closeFiscalYear']);
    Route::post('/{period}/toggle-lock', [PeriodController::class, 'toggleLock']);
    Route::post('/{period}/close', [PeriodController::class, 'close']);
    Route::post('/{period}/reopen', [PeriodController::class, 'reopen']);
});
