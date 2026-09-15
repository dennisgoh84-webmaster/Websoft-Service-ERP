<?php

// Mirrors backend/app/routers/accounts_receivable.py.

use App\Http\Controllers\Api\AccountsReceivableController;
use App\Http\Controllers\Api\PaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('accounts-receivable')->group(function () {
    Route::get('/aging', [AccountsReceivableController::class, 'agingReport']);
    Route::get('/aging/export.csv', [AccountsReceivableController::class, 'agingCsv']);
    Route::get('/aging/export.xlsx', [AccountsReceivableController::class, 'agingExcel']);
    Route::post('/invoices/{invoice}/write-off', [AccountsReceivableController::class, 'writeOffInvoice']);
    Route::post('/invoices/{invoice}/dispute', [AccountsReceivableController::class, 'flagDispute']);
    Route::post('/invoices/{invoice}/ungl', [AccountsReceivableController::class, 'unglInvoice']);

    Route::get('/payments', [PaymentController::class, 'index']);
    Route::get('/payments/export.csv', [PaymentController::class, 'exportCsv']);
    Route::get('/payments/export.xlsx', [PaymentController::class, 'exportExcel']);
    Route::post('/payments', [PaymentController::class, 'store']);
    Route::get('/payments/{payment}', [PaymentController::class, 'show']);
    Route::post('/payments/{payment}/allocate', [PaymentController::class, 'allocate']);
    Route::post('/payments/{payment}/bank', [PaymentController::class, 'bank']);
    Route::post('/payments/{payment}/unbank', [PaymentController::class, 'unbank']);
    Route::post('/payments/{payment}/ungl', [PaymentController::class, 'ungl']);
    Route::get('/payments/{payment}/export.docx', [PaymentController::class, 'exportDocx']);
    Route::post('/payments/{payment}/email', [PaymentController::class, 'email']);

    Route::get('/statement/{customer}', [AccountsReceivableController::class, 'statement']);
    Route::get('/statement/{customer}/export.docx', [AccountsReceivableController::class, 'statementDocx']);
    Route::post('/statement/{customer}/email', [AccountsReceivableController::class, 'statementEmail']);
});
