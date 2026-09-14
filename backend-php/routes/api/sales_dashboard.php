<?php

// NEW FEATURE (not a Python->PHP conversion -- see
// docs/backlog.md / docs/planned-work.md): "Sales Dashboard - Display
// below Company Dashboard".

use App\Http\Controllers\Api\SalesDashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('sales-dashboard')->group(function () {
    Route::get('/summary', [SalesDashboardController::class, 'summary']);

    Route::get('/ar-breakdown', [SalesDashboardController::class, 'arBreakdown']);
    Route::get('/ar-breakdown/export.csv', [SalesDashboardController::class, 'exportArBreakdownCsv']);
    Route::get('/ar-breakdown/export.xls', [SalesDashboardController::class, 'exportArBreakdownExcel']);

    Route::get('/top-billing-customers', [SalesDashboardController::class, 'topBillingCustomers']);
    Route::get('/top-billing-customers/export.csv', [SalesDashboardController::class, 'exportTopBillingCustomersCsv']);
    Route::get('/top-billing-customers/export.xls', [SalesDashboardController::class, 'exportTopBillingCustomersExcel']);

    Route::get('/bottom-non-active-customers', [SalesDashboardController::class, 'bottomNonActiveCustomers']);
    Route::get('/bottom-non-active-customers/export.csv', [SalesDashboardController::class, 'exportBottomNonActiveCustomersCsv']);
    Route::get('/bottom-non-active-customers/export.xls', [SalesDashboardController::class, 'exportBottomNonActiveCustomersExcel']);
});
