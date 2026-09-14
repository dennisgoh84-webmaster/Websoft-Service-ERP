<?php

// Mirrors backend/app/routers/bank_accounts.py. NOT yet converted:
// CSV/Excel export.

use App\Http\Controllers\Api\BankAccountController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('bank-accounts')->group(function () {
    Route::get('/', [BankAccountController::class, 'index']);
    Route::post('/', [BankAccountController::class, 'store']);
    Route::get('/{bankAccount}', [BankAccountController::class, 'show']);
    Route::patch('/{bankAccount}', [BankAccountController::class, 'update']);
});
