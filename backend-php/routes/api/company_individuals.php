<?php

// Mirrors backend/app/routers/company_individuals.py.

use App\Http\Controllers\Api\CompanyIndividualController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('company-individuals')->group(function () {
    Route::get('/', [CompanyIndividualController::class, 'index']);
    Route::get('/export.csv', [CompanyIndividualController::class, 'exportCsv']);
    Route::get('/export.xlsx', [CompanyIndividualController::class, 'exportExcel']);
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

    // Customer Helpdesk Portal access (PORTAL-001..004, design §5) --
    // the STAFF side of the portal, gated by this module's own
    // authority. The customer-facing portal itself is in
    // routes/api/portal.php behind its own auth realm.
    Route::get('/{customer}/contacts/{contact}/portal-access', [CompanyIndividualController::class, 'getPortalAccess']);
    Route::post('/{customer}/contacts/{contact}/portal-access', [CompanyIndividualController::class, 'enablePortalAccess']);
    Route::post('/{customer}/contacts/{contact}/portal-access/reset-password', [CompanyIndividualController::class, 'resetPortalAccessPassword']);
    Route::post('/{customer}/contacts/{contact}/portal-access/disable', [CompanyIndividualController::class, 'disablePortalAccess']);

    Route::get('/{customer}/branches', [CompanyIndividualController::class, 'listBranches']);
    Route::post('/{customer}/branches', [CompanyIndividualController::class, 'createBranch']);
    Route::patch('/{customer}/branches/{branch}', [CompanyIndividualController::class, 'updateBranch']);
    Route::post('/{customer}/branches/{branch}/deactivate', [CompanyIndividualController::class, 'deactivateBranch']);
    Route::post('/{customer}/branches/{branch}/reactivate', [CompanyIndividualController::class, 'reactivateBranch']);

    Route::get('/{customer}/relationships', [CompanyIndividualController::class, 'listRelationships']);
    Route::post('/{customer}/relationships', [CompanyIndividualController::class, 'createRelationship']);
    Route::post('/{customer}/relationships/{relationship}/deactivate', [CompanyIndividualController::class, 'deactivateRelationship']);
});
