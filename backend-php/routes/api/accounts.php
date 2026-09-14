<?php

// Mirrors backend/app/routers/accounts.py. NOT yet converted:
// CSV/Excel export.

use App\Http\Controllers\Api\AccountController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('accounts')->group(function () {
    Route::get('/', [AccountController::class, 'index']);
    Route::post('/', [AccountController::class, 'store']);
    Route::patch('/{account}', [AccountController::class, 'update']);
});
