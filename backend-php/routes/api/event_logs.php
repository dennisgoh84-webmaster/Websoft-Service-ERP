<?php

// Mirrors backend/app/routers/event_logs.py. Read-only: there is no
// create/update/delete endpoint here, by design.

use App\Http\Controllers\Api\EventLogController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('event-logs')->group(function () {
    Route::get('/export.csv', [EventLogController::class, 'exportCsv']);
    Route::get('/export.xlsx', [EventLogController::class, 'exportExcel']);

    Route::get('', [EventLogController::class, 'index']);
});
