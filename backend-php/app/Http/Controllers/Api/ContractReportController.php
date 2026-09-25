<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ScopesReportCompanies;
use App\Http\Controllers\Api\Concerns\SendsExports;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\CompanyIndividual;
use App\Models\Contract;
use App\Models\User;
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
 *
 * Both take `company_ids` (one or several internal companies,
 * 2026-09-25) like the other Operations Reports.
 */
class ContractReportController extends Controller
{
    use ScopesReportCompanies;
    use SendsExports;

    private const MODULE = 'operations_reports';

    private const EXPORT_HEADERS = [
        'Contract Number', 'Company / Individual', 'Status', 'Kind',
        'Contracted Hours', 'Consumed Hours', 'Remaining Hours',
        'Contract Value (SGD)', 'Start Date', 'End Date', 'Quotation Reference',
    ];

    private function present(Contract $contract, array $customerNames, array $scope): array
    {
        return [
            'id' => $contract->id,
            'company_id' => $contract->company_id,
            'company_name' => $scope[$contract->company_id] ?? '',
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

    /** Export headers, with an Internal Company column first when the report spans several. */
    private function exportHeaders(array $scope): array
    {
        return count($scope) > 1 ? ['Internal Company', ...self::EXPORT_HEADERS] : self::EXPORT_HEADERS;
    }

    /** @return array<int, array<int, string|int|float|null>> */
    private function exportRows($contracts, array $customerNames, array $scope): array
    {
        return $contracts->map(fn (Contract $c) => [
            ...(count($scope) > 1 ? [$scope[$c->company_id] ?? ''] : []),
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

    private function customerNames(array $scope): array
    {
        return CompanyIndividual::whereIn('company_id', array_keys($scope))->pluck('name', 'id')->all();
    }

    /** @return array<string, string> */
    private function scope(Request $request, User $user): array
    {
        return $this->reportCompanyScope($request, $user, self::MODULE, 'Operations Reports');
    }

    public function expiryListing(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $scope = $this->scope($request, $user);
        $contracts = ContractService::expiryListing(
            array_keys($scope),
            $request->query('expiry_from'),
            $request->query('expiry_to'),
        );
        $customerNames = $this->customerNames($scope);

        return $contracts->map(fn ($c) => $this->present($c, $customerNames, $scope))->values();
    }

    public function exportExpiryListingCsv(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $scope = $this->scope($request, $user);
        $contracts = ContractService::expiryListing(array_keys($scope), $request->query('expiry_from'), $request->query('expiry_to'));
        Audit::recordReportGenerated($user->id, 'contract_expiry_listing', details: 'CSV export');

        return $this->csvTableResponse($this->exportHeaders($scope), $this->exportRows($contracts, $this->customerNames($scope), $scope), 'contract-expiry-listing.csv');
    }

    public function exportExpiryListingExcel(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $scope = $this->scope($request, $user);
        $contracts = ContractService::expiryListing(array_keys($scope), $request->query('expiry_from'), $request->query('expiry_to'));
        Audit::recordReportGenerated($user->id, 'contract_expiry_listing', details: 'Excel export');

        return $this->xlsxTableResponse($this->exportHeaders($scope), $this->exportRows($contracts, $this->customerNames($scope), $scope), 'Contract Expiry', 'contract-expiry-listing.xlsx');
    }

    public function renewalDueListing(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $scope = $this->scope($request, $user);
        $contracts = ContractService::dueForRenewal(array_keys($scope), $request->query('as_of'));
        $customerNames = $this->customerNames($scope);

        return $contracts->map(fn ($c) => $this->present($c, $customerNames, $scope))->values();
    }

    public function exportRenewalDueListingCsv(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $scope = $this->scope($request, $user);
        $contracts = ContractService::dueForRenewal(array_keys($scope), $request->query('as_of'));
        Audit::recordReportGenerated($user->id, 'contract_renewal_due_listing', details: 'CSV export');

        return $this->csvTableResponse($this->exportHeaders($scope), $this->exportRows($contracts, $this->customerNames($scope), $scope), 'contract-renewal-due-listing.csv');
    }

    public function exportRenewalDueListingExcel(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $scope = $this->scope($request, $user);
        $contracts = ContractService::dueForRenewal(array_keys($scope), $request->query('as_of'));
        Audit::recordReportGenerated($user->id, 'contract_renewal_due_listing', details: 'Excel export');

        return $this->xlsxTableResponse($this->exportHeaders($scope), $this->exportRows($contracts, $this->customerNames($scope), $scope), 'Contract Renewal Due', 'contract-renewal-due-listing.xlsx');
    }
}
