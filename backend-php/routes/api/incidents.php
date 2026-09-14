<?php

// Mirrors backend/app/routers/incidents.py. NOT yet converted (see
// docs/php-conversion-plan.md): convert-to-software-task (the
// Software Tasks module has no backend-php equivalent yet).
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
});
