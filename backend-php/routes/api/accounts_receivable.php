<?php

// Mirrors backend/app/routers/accounts_receivable.py -- the AR-002/003
// invoice-side endpoints plus UNGL. NOT yet converted (see
// docs/php-conversion-plan.md and
// AccountsReceivableController's class docblock): everything
// Payment-related (AR-001, its own module-sized addition), CSV/
// Excel/.docx export.

use App\Http\Controllers\Api\AccountsReceivableController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('accounts-receivable')->group(function () {
    Route::get('/aging', [AccountsReceivableController::class, 'agingReport']);
    Route::post('/invoices/{invoice}/write-off', [AccountsReceivableController::class, 'writeOffInvoice']);
    Route::post('/invoices/{invoice}/dispute', [AccountsReceivableController::class, 'flagDispute']);
    Route::post('/invoices/{invoice}/ungl', [AccountsReceivableController::class, 'unglInvoice']);
});
