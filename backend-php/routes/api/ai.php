<?php

// AI Assistant (docs/planned-work.md #12, slice 1: incident triage +
// resolution suggestions). Settings/usage are Core / Administration;
// the features are gated on the paid `ai_assistant` module key.

use App\Http\Controllers\Api\AiAssistantController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('ai')->group(function () {
    Route::get('/settings', [AiAssistantController::class, 'settings']);
    Route::patch('/settings', [AiAssistantController::class, 'updateSettings']);
    Route::post('/settings/test', [AiAssistantController::class, 'testConnection']);
    Route::get('/usage', [AiAssistantController::class, 'usage']);

    Route::get('/incidents/{incident}/triage', [AiAssistantController::class, 'latestTriage']);
    Route::post('/incidents/{incident}/triage', [AiAssistantController::class, 'triageIncident']);
});
