<?php

use App\Http\Controllers\Api\CrmController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('crm')->group(function () {
    // Prospect Activities
    Route::get('/activities', [CrmController::class, 'index']);
    Route::get('/activities/{activityId}', [CrmController::class, 'show']);
    Route::post('/activities', [CrmController::class, 'store']);
    Route::patch('/activities/{activityId}', [CrmController::class, 'update']);
    Route::delete('/activities/{activityId}', [CrmController::class, 'destroy']);
});
