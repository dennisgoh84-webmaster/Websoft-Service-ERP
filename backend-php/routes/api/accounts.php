<?php

// Mirrors backend/app/routers/accounts.py.

use App\Http\Controllers\Api\AccountController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('accounts')->group(function () {
    Route::get('/', [AccountController::class, 'index']);
    Route::get('/export.csv', [AccountController::class, 'exportCsv']);
    Route::get('/export.xlsx', [AccountController::class, 'exportExcel']);
    Route::post('/', [AccountController::class, 'store']);
    Route::patch('/{account}', [AccountController::class, 'update']);
});
