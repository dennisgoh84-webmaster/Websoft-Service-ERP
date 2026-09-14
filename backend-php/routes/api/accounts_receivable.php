<?php

// Mirrors backend/app/routers/accounts_receivable.py. NOT yet
// converted (see docs/php-conversion-plan.md): CSV/Excel/.docx
// export, "Email Receipt", Customer Statement.

use App\Http\Controllers\Api\AccountsReceivableController;
use App\Http\Controllers\Api\PaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('accounts-receivable')->group(function () {
    Route::get('/aging', [AccountsReceivableController::class, 'agingReport']);
    Route::post('/invoices/{invoice}/write-off', [AccountsReceivableController::class, 'writeOffInvoice']);
    Route::post('/invoices/{invoice}/dispute', [AccountsReceivableController::class, 'flagDispute']);
    Route::post('/invoices/{invoice}/ungl', [AccountsReceivableController::class, 'unglInvoice']);

    Route::get('/payments', [PaymentController::class, 'index']);
    Route::post('/payments', [PaymentController::class, 'store']);
    Route::get('/payments/{payment}', [PaymentController::class, 'show']);
    Route::post('/payments/{payment}/allocate', [PaymentController::class, 'allocate']);
    Route::post('/payments/{payment}/bank', [PaymentController::class, 'bank']);
    Route::post('/payments/{payment}/unbank', [PaymentController::class, 'unbank']);
    Route::post('/payments/{payment}/ungl', [PaymentController::class, 'ungl']);
});
