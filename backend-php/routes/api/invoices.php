<?php

// Mirrors backend/app/routers/billing.py, plus the manually raised
// Sales Invoice (POST /invoices), which is new scope with no Python
// equivalent -- see App\Services\BillingService::issueSalesInvoice.

use App\Http\Controllers\Api\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('invoices')->group(function () {
    Route::get('/', [InvoiceController::class, 'index']);
    // Raise a Sales Invoice by hand, with lines that may pick stock.
    Route::post('/', [InvoiceController::class, 'store']);
    Route::get('/export.csv', [InvoiceController::class, 'exportCsv']);
    Route::get('/export.xlsx', [InvoiceController::class, 'exportExcel']);
    Route::get('/{invoice}', [InvoiceController::class, 'show']);
    Route::get('/{invoice}/export.docx', [InvoiceController::class, 'exportDocx']);
    Route::post('/{invoice}/email', [InvoiceController::class, 'email']);
});
