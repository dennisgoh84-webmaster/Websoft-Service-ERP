<?php

// Mirrors backend/app/routers/company_individuals.py. NOT yet
// converted from the Python router (see docs/php-conversion-plan.md):
// CSV/Excel export, Customer Helpdesk Portal access endpoints, and
// company/individual Relationships.

use App\Http\Controllers\Api\CompanyIndividualController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('company-individuals')->group(function () {
    Route::get('/', [CompanyIndividualController::class, 'index']);
    Route::post('/', [CompanyIndividualController::class, 'store']);
    Route::get('/{customer}', [CompanyIndividualController::class, 'show']);
    Route::patch('/{customer}', [CompanyIndividualController::class, 'update']);
    Route::post('/{customer}/deactivate', [CompanyIndividualController::class, 'deactivate']);
    Route::post('/{customer}/reactivate', [CompanyIndividualController::class, 'reactivate']);
    Route::post('/{customer}/pdpa-consent', [CompanyIndividualController::class, 'pdpaConsent']);
    Route::post('/{customer}/pdpa-agreement-document', [CompanyIndividualController::class, 'pdpaAgreementDocument']);
    Route::post('/{customer}/archive', [CompanyIndividualController::class, 'archive']);
    Route::post('/{customer}/unarchive', [CompanyIndividualController::class, 'unarchive']);
    Route::get('/{customer}/audit-log', [CompanyIndividualController::class, 'auditLog']);

    Route::get('/{customer}/contacts', [CompanyIndividualController::class, 'listContacts']);
    Route::post('/{customer}/contacts', [CompanyIndividualController::class, 'createContact']);
    Route::patch('/{customer}/contacts/{contact}', [CompanyIndividualController::class, 'updateContact']);
    Route::post('/{customer}/contacts/{contact}/deactivate', [CompanyIndividualController::class, 'deactivateContact']);
    Route::post('/{customer}/contacts/{contact}/reactivate', [CompanyIndividualController::class, 'reactivateContact']);

    Route::get('/{customer}/branches', [CompanyIndividualController::class, 'listBranches']);
    Route::post('/{customer}/branches', [CompanyIndividualController::class, 'createBranch']);
    Route::patch('/{customer}/branches/{branch}', [CompanyIndividualController::class, 'updateBranch']);
    Route::post('/{customer}/branches/{branch}/deactivate', [CompanyIndividualController::class, 'deactivateBranch']);
    Route::post('/{customer}/branches/{branch}/reactivate', [CompanyIndividualController::class, 'reactivateBranch']);
});
