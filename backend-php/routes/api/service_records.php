<?php

// Mirrors backend/app/routers/service_records.py. NOT yet converted
// (see docs/php-conversion-plan.md): CSV/Excel export.

use App\Http\Controllers\Api\ServiceRecordController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('service-records')->group(function () {
    Route::get('/', [ServiceRecordController::class, 'index']);
    Route::get('/export.csv', [ServiceRecordController::class, 'exportCsv']);
    Route::get('/export.xlsx', [ServiceRecordController::class, 'exportExcel']);
    Route::post('/', [ServiceRecordController::class, 'store']);
    Route::get('/pending-approval', [ServiceRecordController::class, 'pendingApproval']);
    Route::get('/{serviceRecord}', [ServiceRecordController::class, 'show']);
    Route::post('/{serviceRecord}/approve', [ServiceRecordController::class, 'approve']);
    Route::post('/{serviceRecord}/reject', [ServiceRecordController::class, 'reject']);
    Route::get('/{serviceRecord}/export.docx', [ServiceRecordController::class, 'exportDocx']);
    Route::post('/{serviceRecord}/email', [ServiceRecordController::class, 'email']);
});
