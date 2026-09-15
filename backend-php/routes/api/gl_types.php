<?php

// Mirrors backend/app/routers/gl_types.py.

use App\Http\Controllers\Api\GLTypeController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('gl-types')->group(function () {
    Route::get('', [GLTypeController::class, 'index']);
    Route::post('', [GLTypeController::class, 'store']);
    Route::patch('/{glTypeId}', [GLTypeController::class, 'update']);
});
