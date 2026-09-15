<?php

// Mirrors backend/app/routers/software_tasks.py. The literal /export.*
// paths precede /{taskId} so Laravel does not read them as an id.

use App\Http\Controllers\Api\SoftwareTaskController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('software-tasks')->group(function () {
    Route::get('/export.csv', [SoftwareTaskController::class, 'exportCsv']);
    Route::get('/export.xlsx', [SoftwareTaskController::class, 'exportExcel']);

    Route::get('', [SoftwareTaskController::class, 'index']);
    Route::post('', [SoftwareTaskController::class, 'store']);
    Route::patch('/{taskId}', [SoftwareTaskController::class, 'update']);
    Route::post('/{taskId}/mark-tested', [SoftwareTaskController::class, 'markTested']);
    Route::post('/{taskId}/reopen-testing', [SoftwareTaskController::class, 'reopenTesting']);
});
