<?php

// Email Inbox: the server reads the helpdesk mailbox (Dennis,
// 2026-09-26) -- see App\Services\EmailInbox.

use App\Http\Controllers\Api\EmailInboxController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('email-inbox')->group(function () {
    Route::get('/', [EmailInboxController::class, 'index']);
    Route::post('/check', [EmailInboxController::class, 'check']);
    Route::post('/{email}/log-incident', [EmailInboxController::class, 'logIncident']);
    Route::post('/{email}/convert-to-job-order', [EmailInboxController::class, 'convertToJobOrder']);
    Route::post('/{email}/dismiss', [EmailInboxController::class, 'dismiss']);
    Route::post('/{email}/restore', [EmailInboxController::class, 'restore']);
});
