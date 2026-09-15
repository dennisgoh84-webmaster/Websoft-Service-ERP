<?php

// Customer Helpdesk Portal (PORTAL-001..006). Mirrors
// backend/app/routers/portal.py's own route table exactly.
//
// These routes are NOT staff-RBAC-gated (no `module:` middleware):
// the portal has its own auth realm, per design §8 -- the
// `company_individual_management` module gates the *staff* side
// (enable/disable/reset a Contact's login, in
// routes/api/company_individuals.php), not the portal itself.
//
// `auth.portal` is App\Http\Middleware\AuthenticatePortal, which only
// ever accepts a purpose="portal" token; a staff access token is
// refused here exactly as a portal token is refused by `auth.jwt`.

use App\Http\Controllers\Api\PortalAiController;
use App\Http\Controllers\Api\PortalAuthController;
use App\Http\Controllers\Api\PortalController;
use Illuminate\Support\Facades\Route;

Route::prefix('portal')->group(function () {
    // Unauthenticated: the login sequence itself.
    Route::post('/auth/login', [PortalAuthController::class, 'login']);
    Route::post('/auth/verify-otp', [PortalAuthController::class, 'verifyOtp']);
    Route::post('/auth/forgot-password', [PortalAuthController::class, 'forgotPassword']);
    Route::post('/auth/reset-password-otp', [PortalAuthController::class, 'resetPasswordWithOtp']);

    Route::middleware('auth.portal')->group(function () {
        Route::post('/auth/change-password', [PortalAuthController::class, 'changePassword']);
        Route::get('/me', [PortalAuthController::class, 'me']);

        // Data endpoints (design §6). Every one of these scopes its
        // query to the token's own customer -- ids are never accepted
        // as input for that.
        Route::get('/contracts', [PortalController::class, 'contracts']);
        Route::get('/job-orders', [PortalController::class, 'jobOrders']);
        Route::get('/job-orders/{jobOrder}', [PortalController::class, 'jobOrderDetail']);
        Route::get('/service-records', [PortalController::class, 'serviceRecords']);
        Route::get('/invoices', [PortalController::class, 'invoices']);
        Route::get('/payments', [PortalController::class, 'payments']);
        Route::get('/incidents', [PortalController::class, 'incidents']);
        Route::post('/incidents', [PortalController::class, 'createIncident']);

        // AI Assistant slice 3 (docs/planned-work.md #12 Tier 2 item 6).
        Route::get('/ai/persona', [PortalAiController::class, 'persona']);
        Route::post('/ai/chat', [PortalAiController::class, 'chat']);
    });
});
