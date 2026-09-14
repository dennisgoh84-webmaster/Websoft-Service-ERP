<?php

/*
|--------------------------------------------------------------------------
| API Routes -- Websoft Service ERP Solution
|--------------------------------------------------------------------------
|
| Mirrors backend/app/main.py's router registration. This file is the
| PHP/Laravel equivalent of that FastAPI entrypoint: one router
| `require` per business module, in the same order, so the two trees
| stay easy to diff against each other during the phased conversion --
| see docs/php-conversion-plan.md for what has been converted so far
| and what is still pending.
*/

use Illuminate\Support\Facades\Route;

require __DIR__.'/api/auth.php';
require __DIR__.'/api/companies.php';
require __DIR__.'/api/groups.php';
require __DIR__.'/api/modules.php';
require __DIR__.'/api/company_individuals.php';

// Mirrors backend/app/main.py's GET /api/health.
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'app' => config('app.name'),
    ]);
});
