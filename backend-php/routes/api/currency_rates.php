<?php

// Mirrors backend/app/routers/currency_rates.py.

use App\Http\Controllers\Api\CurrencyRateController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('currency-rates')->group(function () {
    Route::get('', [CurrencyRateController::class, 'index']);
    // For the document forms (multi-currency): any signed-in user.
    Route::get('/as-at', [CurrencyRateController::class, 'asAt']);
    Route::get('/currencies', [CurrencyRateController::class, 'currencies']);
    Route::post('', [CurrencyRateController::class, 'store']);
    Route::patch('/{rateId}', [CurrencyRateController::class, 'update']);
});
