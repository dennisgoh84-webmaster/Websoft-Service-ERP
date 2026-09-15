<?php

// Mirrors backend/app/routers/tax_codes.py. The two literal
// /export.* paths are declared before /{taxCodeId} so Laravel (which
// matches in registration order) does not read "export.csv" as an id.

use App\Http\Controllers\Api\TaxCodeController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('tax-codes')->group(function () {
    Route::get('/export.csv', [TaxCodeController::class, 'exportCsv']);
    Route::get('/export.xlsx', [TaxCodeController::class, 'exportExcel']);

    Route::get('', [TaxCodeController::class, 'index']);
    Route::post('', [TaxCodeController::class, 'store']);
    Route::patch('/{taxCodeId}', [TaxCodeController::class, 'update']);
});
