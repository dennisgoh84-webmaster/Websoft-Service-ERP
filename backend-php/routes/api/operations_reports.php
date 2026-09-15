<?php

// Operations Reports. Mirrors the `/operations/*` half of
// backend/app/routers/reports.py (contracts, job orders, service
// records, customer product usage -- each as JSON plus CSV and XLSX
// exports). The accounting half of that router lives in
// routes/api/reports.php.
//
// No route here takes a path parameter, so the literal `export.csv` /
// `export.xlsx` paths cannot be shadowed by a wildcard; they are kept
// next to their own JSON route, in the order Python declares them.

use App\Http\Controllers\Api\OperationsReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('reports/operations')->group(function () {
    Route::get('/contracts', [OperationsReportController::class, 'contracts']);
    Route::get('/contracts/export.csv', [OperationsReportController::class, 'contractsCsv']);
    Route::get('/contracts/export.xlsx', [OperationsReportController::class, 'contractsExcel']);

    Route::get('/job-orders', [OperationsReportController::class, 'jobOrders']);
    Route::get('/job-orders/export.csv', [OperationsReportController::class, 'jobOrdersCsv']);
    Route::get('/job-orders/export.xlsx', [OperationsReportController::class, 'jobOrdersExcel']);

    Route::get('/service-records', [OperationsReportController::class, 'serviceRecords']);
    Route::get('/service-records/export.csv', [OperationsReportController::class, 'serviceRecordsCsv']);
    Route::get('/service-records/export.xlsx', [OperationsReportController::class, 'serviceRecordsExcel']);

    Route::get('/customer-product-usage', [OperationsReportController::class, 'customerProductUsage']);
    Route::get('/customer-product-usage/export.csv', [OperationsReportController::class, 'customerProductUsageCsv']);
    Route::get('/customer-product-usage/export.xlsx', [OperationsReportController::class, 'customerProductUsageExcel']);
});
