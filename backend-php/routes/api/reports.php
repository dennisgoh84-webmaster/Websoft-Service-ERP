<?php

// Mirrors one endpoint of backend/app/routers/reports.py -- see
// ReportController's class docblock for why only the trial-balance
// duplicate is ported here and not the rest of that router.

use App\Http\Controllers\Api\ReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('reports/accounting')->group(function () {
    Route::get('/trial-balance', [ReportController::class, 'trialBalance']);
});
