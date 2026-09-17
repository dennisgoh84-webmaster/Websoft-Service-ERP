<?php

use App\Http\Controllers\Api\AppVersionController;
use Illuminate\Support\Facades\Route;

// App version visibility -- public endpoints for customers to check version
Route::get('/app/version', [AppVersionController::class, 'current']);
Route::get('/app/version-history', [AppVersionController::class, 'history']);

// Admin version management
Route::middleware('auth.jwt')->group(function () {
    Route::post('/app/versions', [AppVersionController::class, 'store']);
    Route::patch('/app/versions/{versionId}', [AppVersionController::class, 'update']);
});
