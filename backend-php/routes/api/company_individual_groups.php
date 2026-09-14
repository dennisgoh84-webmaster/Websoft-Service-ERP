<?php

// Mirrors backend/app/routers/company_individual_groups.py.

use App\Http\Controllers\Api\CompanyIndividualGroupController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('company-individual-groups')->group(function () {
    Route::get('/', [CompanyIndividualGroupController::class, 'index']);
    Route::post('/', [CompanyIndividualGroupController::class, 'store']);
    Route::patch('/{group}', [CompanyIndividualGroupController::class, 'update']);
});
