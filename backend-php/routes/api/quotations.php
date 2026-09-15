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
    Route::post('/{quotation}/send', [QuotationController::class, 'send']);
    Route::post('/{quotation}/accept', [QuotationController::class, 'accept']);
    Route::post('/{quotation}/reject', [QuotationController::class, 'reject']);
    Route::get('/{quotation}/export.docx', [QuotationController::class, 'exportDocx']);
    Route::post('/{quotation}/email', [QuotationController::class, 'email']);
});
