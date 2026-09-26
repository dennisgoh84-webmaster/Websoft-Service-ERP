<?php

// Credit Notes against Sales Invoices (BILL-003) -- new scope, no Python
// equivalent; see App\Services\CreditNotes.

use App\Http\Controllers\Api\CreditNoteController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('credit-notes')->group(function () {
    Route::get('/', [CreditNoteController::class, 'index']);
    Route::post('/', [CreditNoteController::class, 'store']);
    Route::get('/export.csv', [CreditNoteController::class, 'exportCsv']);
    Route::get('/export.xlsx', [CreditNoteController::class, 'exportExcel']);
    Route::get('/{creditNote}', [CreditNoteController::class, 'show']);
    Route::get('/{creditNote}/export.docx', [CreditNoteController::class, 'exportDocx']);
    Route::post('/{creditNote}/approve', [CreditNoteController::class, 'approve']);
    Route::post('/{creditNote}/reject', [CreditNoteController::class, 'reject']);
    Route::post('/{creditNote}/withdraw', [CreditNoteController::class, 'withdraw']);
});
