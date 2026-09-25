<?php

// Mirrors backend/app/routers/quotations.py. NOT yet converted (see
// docs/php-conversion-plan.md): CSV/Excel export.

use App\Http\Controllers\Api\QuotationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('quotations')->group(function () {
    Route::get('/', [QuotationController::class, 'index']);
    Route::get('/export.csv', [QuotationController::class, 'exportCsv']);
    Route::get('/export.xlsx', [QuotationController::class, 'exportExcel']);
    Route::post('/', [QuotationController::class, 'store']);
    Route::get('/{quotation}', [QuotationController::class, 'show']);
    // Status model (BILL-006, settled 2026-09-15): submit -> approve or
    // send-back -> send -> accept / reject.
    Route::post('/{quotation}/submit', [QuotationController::class, 'submit']);
    Route::post('/{quotation}/approve', [QuotationController::class, 'approve']);
    Route::post('/{quotation}/send-back', [QuotationController::class, 'sendBack']);
    Route::post('/{quotation}/send', [QuotationController::class, 'send']);
    // After sending: accept / reject / to-revise, and the revision itself.
    Route::post('/{quotation}/to-revise', [QuotationController::class, 'toRevise']);
    Route::post('/{quotation}/revise', [QuotationController::class, 'revise']);
    Route::post('/{quotation}/accept', [QuotationController::class, 'accept']);
    Route::post('/{quotation}/reject', [QuotationController::class, 'reject']);
    Route::post('/{quotation}/prospect', [QuotationController::class, 'linkProspect']);
    Route::get('/{quotation}/export.docx', [QuotationController::class, 'exportDocx']);
    Route::post('/{quotation}/email', [QuotationController::class, 'email']);
});
