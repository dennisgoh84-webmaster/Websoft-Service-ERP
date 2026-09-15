<?php

// Mirrors backend/app/routers/contracts.py. NOT yet converted (see
// docs/php-conversion-plan.md): CSV/Excel export.

use App\Http\Controllers\Api\ContractController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('contracts')->group(function () {
    Route::get('/', [ContractController::class, 'index']);
    Route::get('/export.csv', [ContractController::class, 'exportCsv']);
    Route::get('/export.xlsx', [ContractController::class, 'exportExcel']);
    Route::post('/', [ContractController::class, 'store']);
    Route::get('/{contract}', [ContractController::class, 'show']);
    Route::patch('/{contract}', [ContractController::class, 'update']);
    Route::patch('/{contract}/products/{product}', [ContractController::class, 'updateProductLicense']);
    Route::post('/{contract}/activate', [ContractController::class, 'activate']);
    Route::post('/{contract}/renew', [ContractController::class, 'renew']);
    Route::get('/{contract}/excess-usage', [ContractController::class, 'excessUsage']);

    // NEW FEATURE (not a Python->PHP conversion -- see
    // docs/backlog.md / docs/planned-work.md).
    Route::post('/{contract}/quotation-reference', [ContractController::class, 'setQuotationReference']);
    Route::post('/{contract}/shared-customers', [ContractController::class, 'addSharedCustomer']);
    Route::delete('/{contract}/shared-customers/{sharedCustomer}', [ContractController::class, 'removeSharedCustomer']);
});
