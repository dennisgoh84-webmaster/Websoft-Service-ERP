<?php

// NEW FEATURE (not a Python->PHP conversion -- see
// docs/backlog.md / docs/planned-work.md): "Service Contract
// Operation Report - Contract Expiry Listing, Contract due for
// renewal Listing".

use App\Http\Controllers\Api\ContractReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('reports/operations/contracts')->group(function () {
    Route::get('/expiry-listing', [ContractReportController::class, 'expiryListing']);
    Route::get('/expiry-listing/export.csv', [ContractReportController::class, 'exportExpiryListingCsv']);
    Route::get('/expiry-listing/export.xlsx', [ContractReportController::class, 'exportExpiryListingExcel']);

    Route::get('/renewal-due-listing', [ContractReportController::class, 'renewalDueListing']);
    Route::get('/renewal-due-listing/export.csv', [ContractReportController::class, 'exportRenewalDueListingCsv']);
    Route::get('/renewal-due-listing/export.xlsx', [ContractReportController::class, 'exportRenewalDueListingExcel']);
});
