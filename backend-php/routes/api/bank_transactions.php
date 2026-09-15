<?php

// Mirrors backend/app/routers/bank_transactions.py. Note the two
// prefixes: the ledger and reconciliations hang off a bank account,
// while void/toggle-reconciled act on a transaction directly -- the
// same split the Python router declares.

use App\Http\Controllers\Api\BankTransactionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->group(function () {
    Route::get('/bank-accounts/{bankAccountId}/transactions', [BankTransactionController::class, 'index']);
    Route::post('/bank-accounts/{bankAccountId}/transactions', [BankTransactionController::class, 'store']);
    Route::get('/bank-accounts/{bankAccountId}/reconciliations', [BankTransactionController::class, 'listReconciliations']);
    Route::post('/bank-accounts/{bankAccountId}/reconciliations', [BankTransactionController::class, 'storeReconciliation']);

    Route::post('/bank-transactions/{transactionId}/void', [BankTransactionController::class, 'void']);
    Route::post('/bank-transactions/{transactionId}/toggle-reconciled', [BankTransactionController::class, 'toggleReconciled']);
});
