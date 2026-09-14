<?php

// Mirrors backend/app/routers/quotations.py. NOT yet converted (see
// docs/php-conversion-plan.md): CSV/Excel export, the `.docx` export
// and "Email Quotation" endpoints.

use App\Http\Controllers\Api\QuotationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('quotations')->group(function () {
    Route::get('/', [QuotationController::class, 'index']);
    Route::post('/', [QuotationController::class, 'store']);
    Route::get('/{quotation}', [QuotationController::class, 'show']);
    Route::post('/{quotation}/send', [QuotationController::class, 'send']);
    Route::post('/{quotation}/accept', [QuotationController::class, 'accept']);
    Route::post('/{quotation}/reject', [QuotationController::class, 'reject']);
});
