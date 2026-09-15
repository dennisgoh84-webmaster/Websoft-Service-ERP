<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\SendsExports;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\CompanyIndividual;
use App\Models\Contract;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\ContractService;
use Illuminate\Http\Request;

/**
 * NEW FEATURE (not a Python->PHP conversion -- backend/ has no
 * equivalent; built directly in backend-php per Dennis's request, see
 * docs/backlog.md / docs/planned-work.md): "Service Contract
 * Operation Report - Contract Expiry Listing, Contract due for renewal
 * Listing".
 *
 * Two report views over App\Models\Contract:
 * - Expiry Listing: App\Services\ContractService::expiryListing() --
 *   contracts expiring within a date range, or already expired.
 * - Renewal Due Listing: App\Services\ContractService::dueForRenewal()
 *   -- reuses needsPreExpiryCheck()'s SRV-014 30-day pre-expiry window
 *   exactly, so this can never disagree with the contract detail
 *   page's own pre-expiry flag.
 *
 * Gated by the "operations_reports" module (seeded in
 * DatabaseSeeder::MODULE_CATALOG as "Operations Reports (Contracts /
 * Job Orders / Service Records)") -- the same module the existing
 * frontend's OperationsReportsPage.tsx already names in its own header
 * comment for this report family.
 */
class ContractReportController extends Controller
{
    use SendsExports;

    private const MODULE = 'operations_reports';

    private const EXPORT_HEADERS = [
        'Contract Number', 'Company / Individual', 'Status', 'Kind',
        'Contracted Hours', 'Consumed Hours', 'Remaining Hours',
        'Contract Value (SGD)', 'Start Date', 'End Date', 'Quotation Reference',
    ];

    private function present(Contract $contract, array $customerNames): array
    {
        return [
            'id' => $contract->id,
            'contract_number' => $contract->contract_number,
            'customer_id' => $contract->customer_id,
            'customer_name' => $customerNames[$contract->customer_id] ?? '(unknown)',
            'status' => $contract->status,
            'contract_kind' => $contract->contract_kind,
            'contracted_hours' => $contract->contracted_minutes / 60,
            'consumed_hours' => $contract->consumed_minutes / 60,
            'remaining_hours' => $contract->remainingMinutes() / 60,
            'contract_value_sgd' => (float) $contract->contract_value_sgd,
            'start_date' => optional($contract->start_date)->toDateString(),
            'end_date' => optional($contract->end_date)->toDateString(),
            'quotation_reference' => $contract->quotation_reference,
        ];
    }

    /** @return array<int, array<int, string|int|float|null>> */
    private function exportRows($contracts, array $customerNames): array
    {
        return $contracts->map(fn (Contract $c) => [
            $c->contract_number,
            $customerNames[$c->customer_id] ?? '(unknown)',
            $c->status,
            $c->contract_kind,
            round($c->contracted_minutes / 60, 2),
            round($c->consumed_minutes / 60, 2),
            round($c->remainingMinutes() / 60, 2),
            (float) $c->contract_value_sgd,
            optional($c->start_date)->toDateString(),
            optional($c->end_date)->toDateString(),
            $c->quotation_reference,
        ])->values()->all();
    }

    private function customerNames(string $companyId): array
    {
        return CompanyIndividual::where('company_id', $companyId)->pluck('name', 'id')->all();
    }

    public function expiryListing(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $contracts = ContractService::expiryListing(
            $user->company_id,
            $request->query('expiry_from'),
            $request->query('expiry_to'),
        );
        $customerNames = $this->customerNames($user->company_id);

        return $contracts->map(fn ($c) => $this->present($c, $customerNames))->values();
    }

    public function exportExpiryListingCsv(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $contracts = ContractService::expiryListing($user->company_id, $request->query('expiry_from'), $request->query('expiry_to'));
        Audit::recordReportGenerated($user->id, 'contract_expiry_listing', details: 'CSV export');

        return $this->csvTableResponse(self::EXPORT_HEADERS, $this->exportRows($contracts, $this->customerNames($user->company_id)), 'contract-expiry-listing.csv');
    }

    public function exportExpiryListingExcel(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $contracts = ContractService::expiryListing($user->company_id, $request->query('expiry_from'), $request->query('expiry_to'));
        Audit::recordReportGenerated($user->id, 'contract_expiry_listing', details: 'Excel export');

        return $this->xlsxTableResponse(self::EXPORT_HEADERS, $this->exportRows($contracts, $this->customerNames($user->company_id)), 'Contract Expiry', 'contract-expiry-listing.xlsx');
    }

    public function renewalDueListing(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $contracts = ContractService::dueForRenewal($user->company_id, $request->query('as_of'));
        $customerNames = $this->customerNames($user->company_id);

        return $contracts->map(fn ($c) => $this->present($c, $customerNames))->values();
    }

    public function exportRenewalDueListingCsv(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $contracts = ContractService::dueForRenewal($user->company_id, $request->query('as_of'));
        Audit::recordReportGenerated($user->id, 'contract_renewal_due_listing', details: 'CSV export');

        return $this->csvTableResponse(self::EXPORT_HEADERS, $this->exportRows($contracts, $this->customerNames($user->company_id)), 'contract-renewal-due-listing.csv');
    }

    public function exportRenewalDueListingExcel(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $contracts = ContractService::dueForRenewal($user->company_id, $request->query('as_of'));
        Audit::recordReportGenerated($user->id, 'contract_renewal_due_listing', details: 'Excel export');

        return $this->xlsxTableResponse(self::EXPORT_HEADERS, $this->exportRows($contracts, $this->customerNames($user->company_id)), 'Contract Renewal Due', 'contract-renewal-due-listing.xlsx');
    }
}
