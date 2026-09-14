<?php

// Mirrors backend/app/routers/announcements.py. GET /public is the
// one unauthenticated route (the Login page reads it before anyone has
// signed in) -- registered outside the auth.jwt group, the same way
// companies.php registers GET /companies/public-branding.

use App\Http\Controllers\Api\AnnouncementController;
use Illuminate\Support\Facades\Route;

Route::get('/announcements/public', [AnnouncementController::class, 'publicAdBanner']);

Route::middleware('auth.jwt')->prefix('announcements')->group(function () {
    // /settings is declared before /{announcementId} so the literal
    // path is matched first -- Laravel matches in registration order.
    Route::get('/settings', [AnnouncementController::class, 'getSettings']);
    Route::patch('/settings', [AnnouncementController::class, 'updateSettings']);

    Route::get('', [AnnouncementController::class, 'index']);
    Route::post('', [AnnouncementController::class, 'store']);
    Route::patch('/{announcementId}', [AnnouncementController::class, 'update']);
    Route::delete('/{announcementId}', [AnnouncementController::class, 'destroy']);
});
