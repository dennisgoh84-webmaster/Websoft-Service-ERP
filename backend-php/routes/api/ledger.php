<?php

// Mirrors backend/app/routers/ledger.py. The literal /export.* and
// /vouchers paths precede the wildcard ones, as everywhere else.

use App\Http\Controllers\Api\LedgerController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('ledger')->group(function () {
    Route::get('/vouchers/export.csv', [LedgerController::class, 'exportVouchersCsv']);
    Route::get('/vouchers/export.xlsx', [LedgerController::class, 'exportVouchersExcel']);
    Route::get('/vouchers', [LedgerController::class, 'listVouchers']);
    Route::post('/vouchers', [LedgerController::class, 'createVoucher']);
    Route::get('/vouchers/{entryId}', [LedgerController::class, 'getVoucher']);
    Route::post('/vouchers/{entryId}/post', [LedgerController::class, 'postVoucher']);
    Route::post('/vouchers/{entryId}/reverse', [LedgerController::class, 'reverseVoucher']);

    Route::get('/trial-balance/export.csv', [LedgerController::class, 'exportTrialBalanceCsv']);
    Route::get('/trial-balance/export.xlsx', [LedgerController::class, 'exportTrialBalanceExcel']);
    Route::get('/trial-balance', [LedgerController::class, 'trialBalance']);

    Route::get('/transactions/{account}/export.csv', [LedgerController::class, 'exportTransactionsCsv']);
    Route::get('/transactions/{account}/export.xlsx', [LedgerController::class, 'exportTransactionsExcel']);
    Route::get('/transactions/{account}', [LedgerController::class, 'accountTransactions']);
});
