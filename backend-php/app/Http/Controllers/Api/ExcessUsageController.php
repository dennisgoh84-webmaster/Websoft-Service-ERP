<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Exceptions\ContractRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Contract;
use App\Models\ExcessUsageRecord;
use App\Services\Authority;
use App\Services\ExcessUsageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Excess Usage review. Mirrors backend/app/routers/excess_usage.py --
 * see App\Services\ExcessUsageService for the SRV-004/008/011/013
 * business logic this only orchestrates. Uses the same
 * `service_contracts` module gate as the Python router (not a
 * dedicated "excess_usage" module key).
 *
 * NOT yet converted from the Python router: CSV/Excel export.
 */
class ExcessUsageController extends Controller
{
    private const MODULE = 'service_contracts';

    private function present(ExcessUsageRecord $r): array
    {
        return [
            'id' => $r->id,
            'contract_id' => $r->contract_id,
            'service_record_id' => $r->service_record_id,
            'excess_hours' => $r->excess_minutes / 60,
            'treatment' => $r->treatment,
            'reason' => $r->reason,
            'decided_by_user_id' => $r->decided_by_user_id,
            'invoiced' => $r->invoiced,
        ];
    }

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $query = ExcessUsageRecord::where('company_id', $user->company_id);
        if ($request->boolean('pending_only')) {
            $query->whereNull('treatment');
        }

        return $query->get()->map(fn (ExcessUsageRecord $r) => $this->present($r))->values();
    }

    public function decide(Request $request, string $recordId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $record = ExcessUsageRecord::find($recordId);
        if (! $record || $record->company_id !== $user->company_id) {
            throw new ApiException(404, 'Excess usage record not found');
        }
        $contract = Contract::find($record->contract_id);

        $data = $request->validate([
            'treatment' => 'required|in:billable,approved_non_billable,warranty_goodwill,internal_write_off,other',
            'reason' => 'required|string|min:1',
        ]);

        try {
            DB::transaction(fn () => ExcessUsageService::decideExcessUsage(
                $record, $contract, $data['treatment'], $data['reason'], $user,
            ));
        } catch (ContractRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->present($record->fresh()));
    }
}
