<?php

// Mirrors backend/app/routers/announcements.py (the video upload
// endpoints postdate it). GET /public and GET /video/{filename} are
// the unauthenticated routes (the Login page reads/plays them before
// anyone has signed in) -- registered outside the auth.jwt group, the
// same way companies.php registers GET /companies/public-branding.

use App\Http\Controllers\Api\AnnouncementController;
use Illuminate\Support\Facades\Route;

Route::get('/announcements/public/{slot}', [AnnouncementController::class, 'publicAdBanner']);
Route::get('/announcements/video/{filename}', [AnnouncementController::class, 'serveVideo']);

Route::middleware('auth.jwt')->prefix('announcements')->group(function () {
    // /settings/{slot} is declared before /{announcementId} so the
    // literal prefix is matched first -- Laravel matches in
    // registration order.
    Route::get('/settings/{slot}', [AnnouncementController::class, 'getSettings']);
    Route::patch('/settings/{slot}', [AnnouncementController::class, 'updateSettings']);
    Route::post('/settings/{slot}/video', [AnnouncementController::class, 'uploadVideo']);

    Route::get('', [AnnouncementController::class, 'index']);
    Route::post('', [AnnouncementController::class, 'store']);
    Route::patch('/{announcementId}', [AnnouncementController::class, 'update']);
    Route::delete('/{announcementId}', [AnnouncementController::class, 'destroy']);
});
