<?php

// Mirrors backend/app/routers/companies.py.

use App\Http\Controllers\Api\CompanyController;
use Illuminate\Support\Facades\Route;

Route::get('/companies/public-branding', [CompanyController::class, 'publicBranding']);

Route::middleware('auth.jwt')->prefix('companies')->group(function () {
    Route::get('/', [CompanyController::class, 'index']);
    Route::post('/', [CompanyController::class, 'store']);
    Route::patch('/{company}', [CompanyController::class, 'update']);
    // Prove this company's own mailbox works at setup time, rather
    // than discovering it is broken on a real customer invoice.
    Route::post('/{company}/test-email', [CompanyController::class, 'testEmail']);
    Route::post('/{company}/switch', [CompanyController::class, 'switchCompany']);
});
