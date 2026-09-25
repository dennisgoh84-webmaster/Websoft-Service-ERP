<?php

use App\Http\Controllers\Api\ProspectActivityController;
use App\Http\Controllers\Api\ProspectController;
use Illuminate\Support\Facades\Route;

// Prospect / Leads and their activities (module key: prospects).
Route::middleware('auth.jwt')->group(function () {
    Route::get('/prospects', [ProspectController::class, 'index']);
    Route::get('/prospects/export.csv', [ProspectController::class, 'exportCsv']);
    Route::get('/prospects/export.xlsx', [ProspectController::class, 'exportExcel']);
    Route::post('/prospects', [ProspectController::class, 'store']);
    Route::get('/prospects/{prospectId}', [ProspectController::class, 'show']);
    Route::patch('/prospects/{prospectId}', [ProspectController::class, 'update']);

    Route::get('/prospect-activities', [ProspectActivityController::class, 'index']);
    Route::get('/prospect-activities/{activityId}', [ProspectActivityController::class, 'show']);
    Route::post('/prospect-activities', [ProspectActivityController::class, 'store']);
    Route::patch('/prospect-activities/{activityId}', [ProspectActivityController::class, 'update']);
    Route::delete('/prospect-activities/{activityId}', [ProspectActivityController::class, 'destroy']);
});
