<?php

// Mirrors backend/app/routers/service_records.py. NOT yet converted
// (see docs/php-conversion-plan.md): CSV/Excel export, .docx export,
// "Email Service Record".

use App\Http\Controllers\Api\ServiceRecordController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('service-records')->group(function () {
    Route::get('/', [ServiceRecordController::class, 'index']);
    Route::post('/', [ServiceRecordController::class, 'store']);
    Route::get('/pending-approval', [ServiceRecordController::class, 'pendingApproval']);
    Route::get('/{serviceRecord}', [ServiceRecordController::class, 'show']);
    Route::post('/{serviceRecord}/approve', [ServiceRecordController::class, 'approve']);
});
