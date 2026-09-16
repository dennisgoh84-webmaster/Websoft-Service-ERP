<?php

use App\Http\Controllers\Api\SystemMailController;
use Illuminate\Support\Facades\Route;

// Maintenance -> System Email: the two system-level mailboxes (otp,
// helpdesk). Global, like announcements; core_administration-gated.
Route::middleware('auth.jwt')->prefix('system-mail')->group(function () {
    Route::get('', [SystemMailController::class, 'index']);
    Route::patch('/{purpose}', [SystemMailController::class, 'update']);
    Route::post('/{purpose}/test-email', [SystemMailController::class, 'testEmail']);
    Route::post('/{purpose}/test-imap', [SystemMailController::class, 'testImap']);
});
