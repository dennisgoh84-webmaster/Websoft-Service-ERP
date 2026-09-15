<?php

// Mirrors backend/app/routers/setup_lists.py. The literal /export.*
// paths precede /{itemId} so Laravel does not read "export.csv" as an id.

use App\Http\Controllers\Api\SetupListController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('setup-lists')->group(function () {
    Route::get('/export.csv', [SetupListController::class, 'exportCsv']);
    Route::get('/export.xlsx', [SetupListController::class, 'exportExcel']);

    Route::get('', [SetupListController::class, 'index']);
    Route::post('', [SetupListController::class, 'store']);
    Route::patch('/{itemId}', [SetupListController::class, 'update']);
});
