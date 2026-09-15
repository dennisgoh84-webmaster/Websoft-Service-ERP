<?php

// Mirrors backend/app/routers/bank_accounts.py.

use App\Http\Controllers\Api\BankAccountController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('bank-accounts')->group(function () {
    Route::get('/', [BankAccountController::class, 'index']);
    Route::get('/export.csv', [BankAccountController::class, 'exportCsv']);
    Route::get('/export.xlsx', [BankAccountController::class, 'exportExcel']);
    Route::post('/', [BankAccountController::class, 'store']);
    Route::get('/{bankAccount}', [BankAccountController::class, 'show']);
    Route::patch('/{bankAccount}', [BankAccountController::class, 'update']);
});
