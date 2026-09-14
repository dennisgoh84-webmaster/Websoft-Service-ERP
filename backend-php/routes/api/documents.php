<?php

// Mirrors backend/app/routers/documents.py (generic eDocument
// attachments + eSignature for every document type) and
// backend/app/routers/document_control.py (the document-numbering
// admin screen). Both gate on `core_administration`, VIEW to read and
// EDIT/FULL to mutate, exactly as their Python routers do -- enforced
// inside each controller method via Authority::requireModuleAccess()
// rather than as route middleware, so the entity-type check and the
// RBAC check stay in the order the Python handlers apply them.

use App\Http\Controllers\Api\DocumentControlController;
use App\Http\Controllers\Api\DocumentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('documents')->group(function () {
    Route::post('/{entityType}/{entityId}/attachments', [DocumentController::class, 'uploadAttachment']);
    Route::get('/{entityType}/{entityId}/attachments', [DocumentController::class, 'listAttachments']);
    Route::get('/{entityType}/{entityId}/attachments/{attachmentId}/download', [DocumentController::class, 'downloadAttachment']);
    Route::delete('/{entityType}/{entityId}/attachments/{attachmentId}', [DocumentController::class, 'deleteAttachment']);

    Route::post('/{entityType}/{entityId}/signatures', [DocumentController::class, 'addSignature']);
    Route::get('/{entityType}/{entityId}/signatures', [DocumentController::class, 'listSignatures']);
});

Route::middleware('auth.jwt')->prefix('document-control')->group(function () {
    // The formats routes come first: Laravel matches in registration
    // order, so "/formats" must be declared before "/{sequenceId}"
    // would swallow it. (FastAPI's own ordering in document_control.py
    // works out because they use different HTTP methods.)
    Route::get('/formats', [DocumentControlController::class, 'listFormats']);
    Route::put('/formats/{docKind}', [DocumentControlController::class, 'setFormat']);

    Route::get('', [DocumentControlController::class, 'index']);
    Route::patch('/{sequenceId}', [DocumentControlController::class, 'update']);
});
