<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Services\Authority;
use App\Services\PayablesService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Accounts Payable reporting. Mirrors the `/aging` endpoint of
 * backend/app/routers/payables.py.
 */
class AccountsPayableController extends Controller
{
    private const MODULE = 'accounts_payable';

    /** What we owe suppliers, bucketed by how far past due it is. */
    public function agingReport(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $asAt = $request->filled('as_at') ? Carbon::parse($request->query('as_at')) : null;
        [$resolvedAsAt, $rows] = PayablesService::agingRows($user->company_id, $asAt);

        return response()->json([
            'as_at' => $resolvedAsAt->toDateString(),
            'rows' => $rows,
            'total' => array_sum(array_column($rows, 'total')),
        ]);
    }
}
