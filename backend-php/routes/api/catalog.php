<?php

// Mirrors backend/app/routers/catalog.py.

use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('catalog')->group(function () {
    Route::get('/', [ProductController::class, 'index']);
    Route::post('/', [ProductController::class, 'store']);
    Route::patch('/{product}', [ProductController::class, 'update']);
});
