<?php

// Accounting Reports -- the `/accounting/*` half of
// backend/app/routers/reports.py. The Operations half is registered in
// routes/api/operations_reports.php.
//
// Note the trial balance appears twice in this system, here and on
// /ledger/trial-balance: same figures, different Module Control key
// (accounting_reports vs finance_accounting), so a group can be given
// the Accounting Reports screen without the General Ledger screen, or
// the other way round. Python carries the same pair -- see
// ReportController's class docblock.

use App\Http\Controllers\Api\ReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('reports/accounting')->group(function () {
    Route::get('/ar-aging', [ReportController::class, 'arAging']);
    Route::get('/ar-aging/export.csv', [ReportController::class, 'arAgingCsv']);
    Route::get('/ar-aging/export.xlsx', [ReportController::class, 'arAgingExcel']);

    Route::get('/ap-aging', [ReportController::class, 'apAging']);
    Route::get('/ap-aging/export.csv', [ReportController::class, 'apAgingCsv']);
    Route::get('/ap-aging/export.xlsx', [ReportController::class, 'apAgingExcel']);

    Route::get('/trial-balance', [ReportController::class, 'trialBalance']);
    Route::get('/trial-balance/export.csv', [ReportController::class, 'trialBalanceCsv']);
    Route::get('/trial-balance/export.xlsx', [ReportController::class, 'trialBalanceExcel']);

    Route::get('/gst-return', [ReportController::class, 'gstReturn']);
    Route::get('/gst-return/export.csv', [ReportController::class, 'gstReturnCsv']);
    Route::get('/gst-return/export.xlsx', [ReportController::class, 'gstReturnExcel']);

    Route::get('/sales-gp', [ReportController::class, 'salesGp']);
    Route::get('/sales-gp/export.csv', [ReportController::class, 'salesGpCsv']);
    Route::get('/sales-gp/export.xlsx', [ReportController::class, 'salesGpExcel']);

    // Setting the rate is FULL; reading it, and the report itself, is VIEW.
    Route::get('/commission-settings', [ReportController::class, 'commissionSettings']);
    Route::put('/commission-settings', [ReportController::class, 'updateCommissionSettings']);

    Route::get('/commission', [ReportController::class, 'commission']);
    Route::get('/commission/export.csv', [ReportController::class, 'commissionCsv']);
    Route::get('/commission/export.xlsx', [ReportController::class, 'commissionExcel']);
});
