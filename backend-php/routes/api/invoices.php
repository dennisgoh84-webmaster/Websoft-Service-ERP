<?php

// Mirrors backend/app/routers/billing.py. NOT yet converted (see
// docs/php-conversion-plan.md): CSV/Excel export, .docx export,
// "Email Invoice".

use App\Http\Controllers\Api\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('invoices')->group(function () {
    Route::get('/', [InvoiceController::class, 'index']);
    Route::get('/{invoice}', [InvoiceController::class, 'show']);
});
