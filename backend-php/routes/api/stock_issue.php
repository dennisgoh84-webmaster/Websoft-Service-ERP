<?php

// Goods Issue Note -- NEW in backend-php (Dennis, 2026-09-15).
// backend/ has no equivalent router, so this is not a conversion.
// Sits under the same /stock prefix as the other stock documents.

use App\Http\Controllers\Api\GoodsIssueNoteController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('stock')->group(function () {
    Route::get('/gin', [GoodsIssueNoteController::class, 'index']);
    Route::post('/gin', [GoodsIssueNoteController::class, 'store']);
    Route::post('/gin/{ginId}/confirm', [GoodsIssueNoteController::class, 'confirm']);
});
