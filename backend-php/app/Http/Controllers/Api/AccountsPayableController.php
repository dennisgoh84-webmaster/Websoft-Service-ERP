<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\SendsExports;
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
    use SendsExports;

    /** @var array<int, string> */
    private const AGING_EXPORT_FIELDS = [
        'supplier_name', 'current', 'days_1_30', 'days_31_60', 'days_61_90', 'over_90', 'total',
    ];

    private const MODULE = 'accounts_payable';

    /** What we owe suppliers, bucketed by how far past due it is. */
    public function agingReport(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $asAt = $this->asAt($request);
        [$resolvedAsAt, $rows] = PayablesService::agingRows($user->company_id, $asAt);

        return response()->json([
            'as_at' => $resolvedAsAt->toDateString(),
            'rows' => $rows,
            'total' => array_sum(array_column($rows, 'total')),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function agingExportRows(string $companyId, ?Carbon $asAt): array
    {
        [, $rows] = PayablesService::agingRows($companyId, $asAt);

        return array_map(fn (array $r) => [
            'supplier_name' => $r['supplier_name'],
            'current' => number_format($r['current'], 2, '.', ''),
            'days_1_30' => number_format($r['days_1_30'], 2, '.', ''),
            'days_31_60' => number_format($r['days_31_60'], 2, '.', ''),
            'days_61_90' => number_format($r['days_61_90'], 2, '.', ''),
            'over_90' => number_format($r['over_90'], 2, '.', ''),
            'total' => number_format($r['total'], 2, '.', ''),
        ], $rows);
    }

    public function agingCsv(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->csvResponse(
            self::AGING_EXPORT_FIELDS,
            $this->agingExportRows($user->company_id, $this->asAt($request)),
            'ap-aging.csv',
        );
    }

    public function agingExcel(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->xlsxResponse(
            self::AGING_EXPORT_FIELDS,
            $this->agingExportRows($user->company_id, $this->asAt($request)),
            'AP Aging',
            'ap-aging.xlsx',
        );
    }

    private function asAt(Request $request): ?Carbon
    {
        return $request->filled('as_at') ? Carbon::parse($request->query('as_at')) : null;
    }
}
