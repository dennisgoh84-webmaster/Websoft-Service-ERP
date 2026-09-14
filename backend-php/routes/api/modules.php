<?php

// Mirrors backend/app/routers/modules.py.

use App\Http\Controllers\Api\ModuleController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('modules')->group(function () {
    Route::get('/my-access', [ModuleController::class, 'myAccess']);
    Route::get('/', [ModuleController::class, 'index']);
});
