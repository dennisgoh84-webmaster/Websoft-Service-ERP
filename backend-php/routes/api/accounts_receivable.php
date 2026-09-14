<?php

// Mirrors backend/app/routers/accounts_receivable.py -- only the
// AR-002/003 invoice-side endpoints. NOT yet converted (see
// docs/php-conversion-plan.md and
// AccountsReceivableController's class docblock): everything
// Payment-related (needs the still-pending GL posting + Bank
// module), CSV/Excel/.docx export, un-GL/un-bank.

use App\Http\Controllers\Api\AccountsReceivableController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('accounts-receivable')->group(function () {
    Route::get('/aging', [AccountsReceivableController::class, 'agingReport']);
    Route::post('/invoices/{invoice}/write-off', [AccountsReceivableController::class, 'writeOffInvoice']);
    Route::post('/invoices/{invoice}/dispute', [AccountsReceivableController::class, 'flagDispute']);
});
