<?php

// Mirrors backend/app/routers/incidents.py in full. The
// convert-to-software-task route was this module's last remaining
// gap; it landed with the Software Tasks module on 2026-09-15.
//
// The two Outlook Add-in "/from-email..." routes are registered
// before "/{incident}/..." below for the exact same reason the Python
// router gives: a wildcard route parameter would otherwise swallow
// "from-email" as a (garbage) incident id.

use App\Http\Controllers\Api\IncidentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('incidents')->group(function () {
    Route::get('/', [IncidentController::class, 'index']);
    Route::post('/', [IncidentController::class, 'store']);

    Route::post('/from-email', [IncidentController::class, 'storeFromEmail']);
    Route::post('/from-email/convert-to-job-order', [IncidentController::class, 'storeJobOrderFromEmail']);

    Route::get('/{incident}', [IncidentController::class, 'show']);
    Route::patch('/{incident}/customer', [IncidentController::class, 'setCustomer']);
    Route::post('/{incident}/callback', [IncidentController::class, 'callback']);
    Route::post('/{incident}/close', [IncidentController::class, 'close']);
    Route::post('/{incident}/convert-to-quotation', [IncidentController::class, 'convertToQuotation']);
    Route::post('/{incident}/convert-to-job-order', [IncidentController::class, 'convertToJobOrder']);
    Route::post('/{incident}/convert-to-software-task', [IncidentController::class, 'convertToSoftwareTask']);
});
