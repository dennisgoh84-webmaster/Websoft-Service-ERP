<?php

// Mirrors backend/app/routers/mobile.py (planned-work.md #1).
// Literal paths precede wildcard ones, as everywhere else.

use App\Http\Controllers\Api\MobileController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('mobile')->group(function () {
    Route::get('/my-open-timein', [MobileController::class, 'myOpenTimeIn']);

    Route::get('/job-orders', [MobileController::class, 'myJobOrders']);
    Route::get('/job-orders/{jobOrderId}', [MobileController::class, 'jobOrderDetail']);
    Route::post('/job-orders/{jobOrderId}/time-in', [MobileController::class, 'timeIn']);

    Route::post('/service-records/{recordId}/time-out', [MobileController::class, 'timeOut']);
    Route::post('/service-records/{recordId}/attachments', [MobileController::class, 'uploadAttachment']);
    Route::get('/service-records/{recordId}/attachments', [MobileController::class, 'listAttachments']);
    Route::post('/service-records/{recordId}/signoff', [MobileController::class, 'createSignoff']);
    Route::get('/service-records/{recordId}/signoff', [MobileController::class, 'getSignoff']);

    Route::get('/attachments/{attachmentId}/file', [MobileController::class, 'downloadAttachment']);
    Route::delete('/attachments/{attachmentId}', [MobileController::class, 'deleteAttachment']);
});
