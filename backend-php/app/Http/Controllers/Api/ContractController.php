<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Exceptions\ContractRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Contract;
use App\Models\ContractProduct;
use App\Models\Product;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\ContractService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Service Contracts. Mirrors backend/app/routers/contracts.py -- see
 * App\Services\ContractService for the SRV-001..018 business logic
 * this only orchestrates.
 *
 * NOT yet converted from the Python router (tracked in
 * docs/php-conversion-plan.md): CSV/Excel export, and
 * GET /{contract}/excess-usage (needs ExcessUsageRecord, which needs
 * Service Records first).
 *
 * KNOWN GAP, deliberately not silently papered over: the Python
 * router's POST /{contract}/activate also issues the contract's
 * annual invoice (BILL-001/002/005, via app/services/billing.py) in
 * the same transaction. Billing isn't converted yet, so activation
 * here only changes status -- no invoice is issued. This makes
 * `backend-php/` NOT financially equivalent to `backend/` for this one
 * action until Billing is converted; do not treat a contract activated
 * through this backend as billed.
 */
class ContractController extends Controller
{
    private const MODULE = 'service_contracts';

    private function contractOrFail(string $companyId, string $contractId): Contract
    {
        $contract = Contract::with('products.product')->find($contractId);
        if (! $contract || $contract->company_id !== $companyId) {
            throw new ApiException(404, 'Contract not found');
        }

        return $contract;
    }

    private function present(Contract $contract): array
    {
        return [
            'id' => $contract->id,
            'contract_number' => $contract->contract_number,
            'customer_id' => $contract->customer_id,
            'status' => $contract->status,
            'contract_kind' => $contract->contract_kind,
            'contracted_hours' => $contract->contracted_minutes / 60,
            'consumed_hours' => $contract->consumed_minutes / 60,
            'remaining_hours' => $contract->remainingMinutes() / 60,
            'contract_value_sgd' => (float) $contract->contract_value_sgd,
            'hourly_rate_sgd' => $contract->hourly_rate_sgd !== null ? (float) $contract->hourly_rate_sgd : null,
            'sales_staff_id' => $contract->sales_staff_id,
            'start_date' => optional($contract->start_date)->toDateString(),
            'end_date' => optional($contract->end_date)->toDateString(),
            'renewed_from_contract_id' => $contract->renewed_from_contract_id,
            'products' => $contract->products->map(fn (ContractProduct $cp) => [
                'product_id' => $cp->product_id,
                'product_name' => $cp->product?->name,
                'license_type' => $cp->license_type,
                'number_of_licenses' => $cp->number_of_licenses,
            ])->values(),
        ];
    }

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $data = $request->validate([
            'customer_id' => 'required|uuid',
            'contract_kind' => 'sometimes|in:service_support,annual,ad_hoc',
            'contracted_hours' => 'sometimes|numeric|min:0',
            'contract_value_sgd' => 'sometimes|numeric|min:0',
            'start_date' => 'required|date',
            'hourly_rate_sgd' => 'sometimes|nullable|numeric|gt:0',
            'sales_staff_id' => 'sometimes|nullable|uuid',
            'product_ids' => 'sometimes|array',
            'product_ids.*' => 'uuid',
        ]);

        try {
            $contract = DB::transaction(fn () => ContractService::createContract(
                companyId: $user->company_id,
                customerId: $data['customer_id'],
                contractedHours: (float) ($data['contracted_hours'] ?? 0),
                contractValueSgd: (float) ($data['contract_value_sgd'] ?? 0),
                startDate: $data['start_date'],
                actorUserId: $user->id,
                contractKind: $data['contract_kind'] ?? Contract::KIND_SERVICE_SUPPORT,
                hourlyRateSgd: isset($data['hourly_rate_sgd']) ? (float) $data['hourly_rate_sgd'] : null,
                salesStaffId: $data['sales_staff_id'] ?? null,
                productIds: $data['product_ids'] ?? [],
            ));
        } catch (ContractRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->present($contract->fresh('products.product')));
    }

    private function filterContracts(Request $request, string $companyId)
    {
        $query = Contract::with('products.product')->where('company_id', $companyId);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }
        if ($request->filled('contract_kind')) {
            $query->where('contract_kind', $request->query('contract_kind'));
        }
        if ($request->filled('sales_staff_id')) {
            $query->where('sales_staff_id', $request->query('sales_staff_id'));
        }
        if ($request->filled('product_id')) {
            $query->whereHas('products', fn ($q) => $q->where('product_id', $request->query('product_id')));
        }
        // Coverage-date range: any contract whose own start/end
        // overlaps the given window.
        if ($request->filled('coverage_start')) {
            $query->where('end_date', '>=', $request->query('coverage_start'));
        }
        if ($request->filled('coverage_end')) {
            $query->where('start_date', '<=', $request->query('coverage_end'));
        }

        return $query->orderBy('end_date')->get();
    }

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->filterContracts($request, $user->company_id)->map(fn ($c) => $this->present($c))->values();
    }

    /**
     * Admin fields adjustable without a renewal -- sales staff owner
     * and product coverage. Everything else about a contract (kind,
     * hours, value, term) only changes via renewal (SRV-010).
     */
    public function update(Request $request, string $contractId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $contract = $this->contractOrFail($user->company_id, $contractId);
        $fields = $request->validate([
            'sales_staff_id' => 'sometimes|nullable|uuid',
            'product_ids' => 'sometimes|nullable|array',
            'product_ids.*' => 'uuid',
        ]);

        $oldValue = [];
        $newValue = [];

        if (array_key_exists('sales_staff_id', $fields)) {
            $oldValue['sales_staff_id'] = $contract->sales_staff_id;
            $contract->sales_staff_id = $fields['sales_staff_id'];
            $newValue['sales_staff_id'] = $contract->sales_staff_id;
        }

        if (! empty($fields['product_ids'] ?? null)) {
            $oldValue['product_ids'] = $contract->products->pluck('product_id')->all();
            ContractProduct::where('contract_id', $contract->id)->delete();
            foreach ($fields['product_ids'] as $productId) {
                $product = Product::find($productId);
                if ($product === null || $product->company_id !== $user->company_id) {
                    throw new ApiException(404, 'Unknown product in product coverage');
                }
                ContractProduct::create(['contract_id' => $contract->id, 'product_id' => $productId]);
            }
            $newValue['product_ids'] = $fields['product_ids'];
        }

        Audit::record('contract', $contract->id, 'updated', $user->id, oldValue: $oldValue ?: null, newValue: $newValue ?: null);
        $contract->save();

        return response()->json($this->present($contract->fresh('products.product')));
    }

    /**
     * License tracking on one covered product -- separate from
     * update()'s product_ids, which replaces the whole coverage list
     * wholesale (and so would otherwise wipe these two fields every
     * time coverage is edited).
     */
    public function updateProductLicense(Request $request, string $contractId, string $productId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $contract = $this->contractOrFail($user->company_id, $contractId);
        $contractProduct = $contract->products->firstWhere('product_id', $productId);
        if ($contractProduct === null) {
            throw new ApiException(404, 'That product is not covered by this contract');
        }

        $fields = $request->validate([
            'license_type' => 'sometimes|nullable|in:local,rdp,web',
            'number_of_licenses' => 'sometimes|nullable|integer|min:1',
        ]);

        $oldValue = ['license_type' => $contractProduct->license_type, 'number_of_licenses' => $contractProduct->number_of_licenses];
        if (array_key_exists('license_type', $fields)) {
            $contractProduct->license_type = $fields['license_type'];
        }
        if (array_key_exists('number_of_licenses', $fields)) {
            $contractProduct->number_of_licenses = $fields['number_of_licenses'];
        }

        Audit::record(
            'contract_product', $contractProduct->id, 'updated', $user->id,
            details: "{$contract->contract_number}: {$contractProduct->product?->name}",
            oldValue: $oldValue,
            newValue: ['license_type' => $contractProduct->license_type, 'number_of_licenses' => $contractProduct->number_of_licenses],
        );
        $contractProduct->save();

        return response()->json($this->present($contract->fresh('products.product')));
    }

    public function show(Request $request, string $contractId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return response()->json($this->present($this->contractOrFail($user->company_id, $contractId)));
    }

    public function activate(Request $request, string $contractId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $contract = $this->contractOrFail($user->company_id, $contractId);
        try {
            DB::transaction(function () use ($contract, $user) {
                ContractService::activateContract($contract, $user->id);
                // BILL-001/002/005 auto-invoice-on-activation is NOT
                // performed here -- see this controller's class
                // docblock ("KNOWN GAP").
            });
        } catch (ContractRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->present($contract->fresh('products.product')));
    }

    public function renew(Request $request, string $contractId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $prior = $this->contractOrFail($user->company_id, $contractId);
        $data = $request->validate([
            'contracted_hours' => 'required|numeric|min:0',
            'contract_value_sgd' => 'required|numeric|min:0',
            'force_start_date' => 'sometimes|nullable|date',
            'hourly_rate_sgd' => 'sometimes|nullable|numeric|gt:0',
        ]);

        try {
            $newContract = DB::transaction(fn () => ContractService::renewContract(
                priorContract: $prior,
                contractedHours: (float) $data['contracted_hours'],
                contractValueSgd: (float) $data['contract_value_sgd'],
                actorUserId: $user->id,
                forceStartDate: $data['force_start_date'] ?? null,
                hourlyRateSgd: isset($data['hourly_rate_sgd']) ? (float) $data['hourly_rate_sgd'] : null,
            ));
        } catch (ContractRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->present($newContract->fresh('products.product')));
    }
}
