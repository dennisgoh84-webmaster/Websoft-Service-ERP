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
require __DIR__.'/api/users.php';
require __DIR__.'/api/companies.php';
require __DIR__.'/api/groups.php';
require __DIR__.'/api/modules.php';
require __DIR__.'/api/company_individuals.php';
require __DIR__.'/api/company_individual_groups.php';
require __DIR__.'/api/catalog.php';
require __DIR__.'/api/quotations.php';
require __DIR__.'/api/contracts.php';
require __DIR__.'/api/job_orders.php';
require __DIR__.'/api/service_records.php';
require __DIR__.'/api/excess_usage.php';
require __DIR__.'/api/incidents.php';
require __DIR__.'/api/invoices.php';
require __DIR__.'/api/accounts_receivable.php';
require __DIR__.'/api/payables.php';
require __DIR__.'/api/accounts.php';
require __DIR__.'/api/ledger.php';
require __DIR__.'/api/reports.php';
require __DIR__.'/api/bank_accounts.php';
require __DIR__.'/api/periods.php';
require __DIR__.'/api/stock.php';
require __DIR__.'/api/dashboard.php';
require __DIR__.'/api/documents.php';
require __DIR__.'/api/announcements.php';
require __DIR__.'/api/portal.php';

// NEW FEATURES (not Python->PHP conversions -- built directly in
// backend-php per Dennis's request; backend/ has no equivalent for
// any of these. See docs/backlog.md / docs/planned-work.md).
require __DIR__.'/api/contract_reports.php';
require __DIR__.'/api/sales_dashboard.php';

// Mirrors backend/app/main.py's GET /api/health.
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'app' => config('app.name'),
    ]);
});
