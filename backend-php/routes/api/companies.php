<?php

// Mirrors backend/app/routers/companies.py.

use App\Http\Controllers\Api\CompanyController;
use Illuminate\Support\Facades\Route;

Route::get('/companies/public-branding', [CompanyController::class, 'publicBranding']);

Route::middleware('auth.jwt')->prefix('companies')->group(function () {
    Route::get('/', [CompanyController::class, 'index']);
    Route::post('/', [CompanyController::class, 'store']);
    Route::patch('/{company}', [CompanyController::class, 'update']);
    Route::post('/{company}/switch', [CompanyController::class, 'switchCompany']);
});
