<?php

// Mirrors backend/app/routers/users.py.

use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('users')->group(function () {
    Route::get('/', [UserController::class, 'index']);
    Route::post('/', [UserController::class, 'store']);
    Route::get('/{user}', [UserController::class, 'show']);
    Route::patch('/{user}', [UserController::class, 'update']);
    Route::get('/{user}/company-access', [UserController::class, 'companyAccess']);
    Route::put('/{user}/company-access', [UserController::class, 'setCompanyAccess']);
    Route::get('/{user}/audit-log', [UserController::class, 'auditLog']);
    Route::post('/{user}/deactivate', [UserController::class, 'deactivate']);
    Route::post('/{user}/reactivate', [UserController::class, 'reactivate']);
    Route::post('/{user}/reset-password', [UserController::class, 'resetPassword']);
});
