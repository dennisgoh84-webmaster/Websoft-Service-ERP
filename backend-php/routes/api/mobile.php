<?php

use App\Http\Controllers\Api\MobileCrmController;
use App\Http\Controllers\Api\MobileJobOrderController;
use Illuminate\Support\Facades\Route;

// Mobile app routes (optimized for small screens, own-records-only visibility)
// Same auth as desktop but simplified for mobile context
Route::middleware('auth.jwt')->prefix('mobile')->group(function () {
    // CRM Activities (for sales staff/managers)
    Route::get('/crm/activities', [MobileCrmController::class, 'index']);
    Route::get('/crm/activities/{activityId}', [MobileCrmController::class, 'show']);
    Route::post('/crm/activities', [MobileCrmController::class, 'store']);
    Route::patch('/crm/activities/{activityId}', [MobileCrmController::class, 'update']);
    Route::delete('/crm/activities/{activityId}', [MobileCrmController::class, 'destroy']);

    // Job Orders & Service Records (for support staff)
    Route::get('/job-orders', [MobileJobOrderController::class, 'index']);
    Route::get('/job-orders/{jobOrderId}', [MobileJobOrderController::class, 'show']);
    Route::post('/job-orders/{jobOrderId}/time-in', [MobileJobOrderController::class, 'timeIn']);
    Route::post('/service-records/{recordId}/time-out', [MobileJobOrderController::class, 'timeOut']);
    Route::get('/service-records/{recordId}/attachments', [MobileJobOrderController::class, 'listAttachments']);
    Route::post('/service-records/{recordId}/attachments', [MobileJobOrderController::class, 'uploadAttachment']);
    Route::get('/service-records/{recordId}/signoff', [MobileJobOrderController::class, 'getSignoff']);
    Route::post('/service-records/{recordId}/signoff', [MobileJobOrderController::class, 'saveSignoff']);
    Route::get('/my-open-timein', [MobileJobOrderController::class, 'getOpenTimeIn']);

    // Attachments (view/delete)
    Route::get('/attachments/{attachmentId}/file', [MobileJobOrderController::class, 'downloadAttachment']);
    Route::delete('/attachments/{attachmentId}', [MobileJobOrderController::class, 'deleteAttachment']);
});
