<?php

// Mirrors backend/app/routers/auth.py.

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/send-otp', [AuthController::class, 'sendOtp']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password-otp', [AuthController::class, 'resetPasswordWithOtp']);
    // Gmail add-on sign-in: a one-time code from the signed-in web app.
    Route::post('/addin-connect-code', [AuthController::class, 'addinConnectCode'])->middleware('auth.jwt');
    Route::post('/addin-connect', [AuthController::class, 'addinConnect'])->middleware('throttle:10,1');
    Route::get('/me', [AuthController::class, 'me'])->middleware('auth.jwt');
    Route::post('/ai-consent', [AuthController::class, 'acknowledgeAiConsent'])->middleware('auth.jwt');
});
