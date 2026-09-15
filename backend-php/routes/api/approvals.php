<?php

// Mirrors backend/app/routers/approvals.py (planned-work.md #4).
// Literal paths precede wildcard ones.

use App\Http\Controllers\Api\ApprovalController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('approvals')->group(function () {
    Route::get('/pending', [ApprovalController::class, 'listPending']);
    Route::post('/submit', [ApprovalController::class, 'submit']);

    Route::get('/authorities', [ApprovalController::class, 'listAuthorities']);
    Route::post('/authorities', [ApprovalController::class, 'createAuthority']);
    Route::get('/authorities/{authorityId}', [ApprovalController::class, 'getAuthority']);
    Route::patch('/authorities/{authorityId}', [ApprovalController::class, 'updateAuthority']);
    Route::post('/authorities/{authorityId}/members', [ApprovalController::class, 'addMember']);
    Route::delete('/authorities/{authorityId}/members/{memberId}', [ApprovalController::class, 'removeMember']);

    Route::post('/rules', [ApprovalController::class, 'createRule']);
    Route::patch('/rules/{ruleId}', [ApprovalController::class, 'updateRule']);
    Route::delete('/rules/{ruleId}', [ApprovalController::class, 'deleteRule']);

    Route::post('/requests/{requestId}/decide', [ApprovalController::class, 'decide']);
    Route::get('/entity/{entityType}/{entityId}', [ApprovalController::class, 'listForEntity']);
});
