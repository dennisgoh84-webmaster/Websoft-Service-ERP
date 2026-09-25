<?php

// Maintenance -> Data Migration (docs/data-migration.md): ODOO / ZSOFT
// upload -> map fields -> dry run -> import -> roll back. Gated on
// `data_migration` inside the controller (VIEW to look, FULL to act).

use App\Http\Controllers\Api\DataMigrationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('data-migration')->group(function () {
    Route::get('/overview', [DataMigrationController::class, 'overview']);
    Route::get('/modules/export.csv', [DataMigrationController::class, 'modulesCsv']);
    Route::get('/modules/export.xlsx', [DataMigrationController::class, 'modulesExcel']);

    Route::get('/modules/{source}/{entity}/mapping', [DataMigrationController::class, 'mapping']);
    Route::put('/modules/{source}/{entity}/mapping', [DataMigrationController::class, 'updateMapping']);
    Route::post('/modules/{source}/{entity}/sign-off', [DataMigrationController::class, 'signOff']);
    Route::get('/modules/{source}/{entity}/field-gap.csv', [DataMigrationController::class, 'fieldGapCsv']);
    Route::get('/modules/{source}/{entity}/field-gap.xlsx', [DataMigrationController::class, 'fieldGapExcel']);

    Route::get('/batches/export.csv', [DataMigrationController::class, 'batchesCsv']);
    Route::get('/batches/export.xlsx', [DataMigrationController::class, 'batchesExcel']);
    Route::get('/batches', [DataMigrationController::class, 'batches']);
    Route::post('/batches', [DataMigrationController::class, 'upload']);
    Route::get('/batches/{batch}', [DataMigrationController::class, 'show']);
    Route::get('/batches/{batch}/progress', [DataMigrationController::class, 'progress']);
    Route::put('/batches/{batch}/decisions', [DataMigrationController::class, 'decisions']);
    Route::post('/batches/{batch}/dry-run', [DataMigrationController::class, 'dryRun']);
    Route::post('/batches/{batch}/import', [DataMigrationController::class, 'import']);
    Route::post('/batches/{batch}/rollback', [DataMigrationController::class, 'rollback']);
    Route::get('/batches/{batch}/problems.csv', [DataMigrationController::class, 'problemsCsv']);
    Route::get('/batches/{batch}/problems.xlsx', [DataMigrationController::class, 'problemsExcel']);
});
