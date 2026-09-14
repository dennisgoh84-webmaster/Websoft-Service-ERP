<?php

// Mirrors the trial-balance and account-transactions endpoints of
// backend/app/routers/ledger.py. NOT yet converted: the manual
// Journal Voucher CRUD endpoints (/ledger/vouchers*) and CSV/Excel
// export for either report -- see LedgerController's class docblock
// and docs/php-conversion-plan.md.

use App\Http\Controllers\Api\LedgerController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('ledger')->group(function () {
    Route::get('/trial-balance', [LedgerController::class, 'trialBalance']);
    Route::get('/transactions/{account}', [LedgerController::class, 'accountTransactions']);
});
