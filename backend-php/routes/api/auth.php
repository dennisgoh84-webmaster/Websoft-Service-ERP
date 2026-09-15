<?php

// Mirrors backend/app/routers/auth.py.

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password-otp', [AuthController::class, 'resetPasswordWithOtp']);
    Route::get('/me', [AuthController::class, 'me'])->middleware('auth.jwt');
    Route::post('/ai-consent', [AuthController::class, 'acknowledgeAiConsent'])->middleware('auth.jwt');
});
