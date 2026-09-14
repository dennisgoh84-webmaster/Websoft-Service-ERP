<?php

// Mirrors backend/app/routers/catalog.py.

use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('catalog')->group(function () {
    Route::get('/', [ProductController::class, 'index']);
    Route::post('/', [ProductController::class, 'store']);
    Route::patch('/{product}', [ProductController::class, 'update']);

    // NEW FEATURE (not a Python->PHP conversion -- see
    // docs/backlog.md / docs/planned-work.md): Job Implementation
    // Template.
    Route::get('/{product}/implementation-template', [ProductController::class, 'getImplementationTemplate']);
    Route::put('/{product}/implementation-template', [ProductController::class, 'setImplementationTemplate']);
});
