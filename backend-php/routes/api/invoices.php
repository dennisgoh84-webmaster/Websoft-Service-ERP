<?php

// Mirrors backend/app/routers/billing.py. NOT yet converted (see
// docs/php-conversion-plan.md): CSV/Excel export.

use App\Http\Controllers\Api\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('invoices')->group(function () {
    Route::get('/', [InvoiceController::class, 'index']);
    Route::get('/export.csv', [InvoiceController::class, 'exportCsv']);
    Route::get('/export.xlsx', [InvoiceController::class, 'exportExcel']);
    Route::get('/{invoice}', [InvoiceController::class, 'show']);
    Route::get('/{invoice}/export.docx', [InvoiceController::class, 'exportDocx']);
    Route::post('/{invoice}/email', [InvoiceController::class, 'email']);
});
