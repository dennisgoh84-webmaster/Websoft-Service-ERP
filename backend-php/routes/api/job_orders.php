<?php

// Mirrors backend/app/routers/job_orders.py. NOT yet converted (see
// docs/php-conversion-plan.md): CSV/Excel export.

use App\Http\Controllers\Api\JobOrderController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('job-orders')->group(function () {
    Route::get('/', [JobOrderController::class, 'index']);
    Route::get('/export.csv', [JobOrderController::class, 'exportCsv']);
    Route::get('/export.xlsx', [JobOrderController::class, 'exportExcel']);
    Route::post('/', [JobOrderController::class, 'store']);
    Route::get('/{jobOrder}', [JobOrderController::class, 'show']);
    Route::post('/{jobOrder}/assign', [JobOrderController::class, 'assign']);
    Route::post('/{jobOrder}/due-date', [JobOrderController::class, 'setDueDate']);
    Route::post('/{jobOrder}/urgent', [JobOrderController::class, 'setUrgent']);
    Route::post('/{jobOrder}/billing-classification', [JobOrderController::class, 'setBillingClassification']);
    Route::post('/{jobOrder}/void', [JobOrderController::class, 'void']);
    Route::post('/{jobOrder}/reopen', [JobOrderController::class, 'reopen']);
    Route::post('/{jobOrder}/approve-overrun', [JobOrderController::class, 'approveOverrun']);

    Route::post('/{jobOrder}/milestones', [JobOrderController::class, 'addMilestone']);
    Route::get('/{jobOrder}/milestones', [JobOrderController::class, 'listMilestones']);
    Route::put('/{jobOrder}/milestones/{milestone}', [JobOrderController::class, 'updateMilestone']);
    Route::delete('/{jobOrder}/milestones/{milestone}', [JobOrderController::class, 'deleteMilestone']);
    Route::post('/{jobOrder}/milestones/init-template', [JobOrderController::class, 'initMilestoneTemplate']);

    // NEW FEATURE (not a Python->PHP conversion -- see
    // docs/backlog.md / docs/planned-work.md): multi-Product selection
    // + Job Implementation Template import.
    Route::post('/{jobOrder}/products', [JobOrderController::class, 'addProducts']);
    Route::post('/{jobOrder}/implementation-tasks/{task}/complete', [JobOrderController::class, 'completeImplementationTask']);
    Route::post('/{jobOrder}/implementation-tasks/{task}/reopen', [JobOrderController::class, 'reopenImplementationTask']);
});
