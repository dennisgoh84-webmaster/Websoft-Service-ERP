<?php

// Bank Portal Testing -- module-gated placeholder, see
// App\Http\Controllers\Api\BankPortalController's docblock.

use App\Http\Controllers\Api\BankPortalController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('bank-portal')->group(function () {
    Route::get('/status', [BankPortalController::class, 'status']);
});
