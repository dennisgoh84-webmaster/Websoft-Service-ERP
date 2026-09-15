<?php

// Commission Payouts -- mirrors backend/app/routers/commissions.py.
// The literal `submit-all`/`approve-all` collection actions are
// registered before the `{payout}` wildcard, so they are never taken
// for a payout id.

use App\Http\Controllers\Api\CommissionPayoutController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('commissions/payouts')->group(function () {
    Route::post('/generate', [CommissionPayoutController::class, 'generate']);
    Route::post('/submit-all', [CommissionPayoutController::class, 'submitAll']);
    Route::post('/approve-all', [CommissionPayoutController::class, 'approveAll']);

    Route::get('/', [CommissionPayoutController::class, 'index']);
    Route::get('/{payout}', [CommissionPayoutController::class, 'show']);
    Route::post('/{payout}/submit', [CommissionPayoutController::class, 'submit']);
    Route::post('/{payout}/approve', [CommissionPayoutController::class, 'approve']);
    Route::post('/{payout}/reject', [CommissionPayoutController::class, 'reject']);
    Route::post('/{payout}/pay', [CommissionPayoutController::class, 'pay']);
    Route::post('/{payout}/cancel', [CommissionPayoutController::class, 'cancel']);
});
