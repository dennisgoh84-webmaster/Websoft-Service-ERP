<?php

// Mirrors backend/app/routers/currency_rates.py.

use App\Http\Controllers\Api\CurrencyRateController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('currency-rates')->group(function () {
    Route::get('', [CurrencyRateController::class, 'index']);
    Route::post('', [CurrencyRateController::class, 'store']);
    Route::patch('/{rateId}', [CurrencyRateController::class, 'update']);
});
