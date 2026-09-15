<?php

// Mirrors backend/app/routers/reference_codes.py. The literal
// /export.* paths precede /{referenceCodeId} for the same reason.

use App\Http\Controllers\Api\ReferenceCodeController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('reference-codes')->group(function () {
    Route::get('/export.csv', [ReferenceCodeController::class, 'exportCsv']);
    Route::get('/export.xlsx', [ReferenceCodeController::class, 'exportExcel']);

    Route::get('', [ReferenceCodeController::class, 'index']);
    Route::post('', [ReferenceCodeController::class, 'store']);
    Route::patch('/{referenceCodeId}', [ReferenceCodeController::class, 'update']);
});
