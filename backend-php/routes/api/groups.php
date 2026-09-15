<?php

// Mirrors backend/app/routers/groups.py.

use App\Http\Controllers\Api\GroupController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('groups')->group(function () {
    Route::get('/', [GroupController::class, 'index']);
    Route::get('/export.csv', [GroupController::class, 'exportCsv']);
    Route::get('/export.xlsx', [GroupController::class, 'exportExcel']);
    Route::post('/', [GroupController::class, 'store']);
    Route::get('/{group}', [GroupController::class, 'show']);
    Route::patch('/{group}', [GroupController::class, 'update']);
    Route::delete('/{group}', [GroupController::class, 'destroy']);
    Route::put('/{group}/authorities', [GroupController::class, 'setAuthorities']);
});
