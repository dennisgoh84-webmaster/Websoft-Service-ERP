<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Exceptions\ContractRuleViolation;
use App\Http\Controllers\Api\Concerns\SendsExports;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\CompanyIndividual;
use App\Models\Contract;
use App\Models\ExcessUsageRecord;
use App\Services\Authority;
use App\Services\ExcessUsageService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
    use SendsExports;

    /** @var array<int, string> */
    private const EXPORT_FIELDS = ['customer_name', 'excess_hours', 'treatment', 'reason', 'invoiced'];

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

        return $this->filtered($user->company_id, $request)
            ->map(fn (ExcessUsageRecord $r) => $this->present($r))->values();
    }

    /**
     * The list the screen shows, honouring its filter -- shared with
     * the exports so an Export button always returns what is on
     * screen.
     *
     * @return Collection<int, ExcessUsageRecord>
     */
    private function filtered(string $companyId, Request $request)
    {
        $query = ExcessUsageRecord::where('company_id', $companyId);
        if ($request->boolean('pending_only')) {
            $query->whereNull('treatment');
        }

        return $query->get();
    }

    /** @return array<int, array<string, mixed>> */
    private function exportRows(string $companyId, Request $request): array
    {
        $records = $this->filtered($companyId, $request);
        $contractCustomers = Contract::whereIn('id', $records->pluck('contract_id')->unique())
            ->pluck('customer_id', 'id');
        $customerNames = CompanyIndividual::where('company_id', $companyId)->pluck('name', 'id');

        return $records->map(fn (ExcessUsageRecord $r) => [
            // The excess belongs to a contract, and the contract to a
            // customer -- an excess record has no customer of its own.
            'customer_name' => $customerNames[$contractCustomers[$r->contract_id] ?? ''] ?? '',
            'excess_hours' => number_format($r->excess_minutes / 60, 2, '.', ''),
            'treatment' => $r->treatment ?? '',
            'reason' => $r->reason ?? '',
            'invoiced' => $r->invoiced,
        ])->all();
    }

    public function exportCsv(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->csvResponse(
            self::EXPORT_FIELDS, $this->exportRows($user->company_id, $request), 'excess-usage.csv'
        );
    }

    public function exportExcel(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->xlsxResponse(
            self::EXPORT_FIELDS, $this->exportRows($user->company_id, $request), 'Excess Usage', 'excess-usage.xlsx'
        );
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
