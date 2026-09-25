<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\CompanyController;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\User;
use App\Services\Authority;
use Illuminate\Http\Request;

/**
 * The "Internal Companies" a report covers (Dennis, 2026-09-24/25): one
 * or several of the viewer's own companies, sent as `company_ids`
 * (comma-separated). None sent = the company the viewer is signed in
 * to, so every existing caller keeps its single-company behaviour.
 *
 * Shared by the Accounting, Operations and Stock report controllers.
 */
trait ScopesReportCompanies
{
    /**
     * Each requested company must be one the viewer can switch to and,
     * for anyone but the owner, have the report's module switched on.
     *
     * @return array<string, string> company id => "CODE Name", in the order requested
     */
    protected function reportCompanyScope(Request $request, User $user, string $module, string $moduleLabel): array
    {
        $requested = $this->requestIdList($request, 'company_ids');
        $ids = $requested === [] ? [$user->company_id] : $requested;
        $accessible = CompanyController::accessibleCompanyIds($user);
        foreach ($ids as $id) {
            if (! in_array($id, $accessible, true)) {
                throw new ApiException(403, 'You do not have access to one of the selected companies.');
            }
            if ($user->role !== User::ROLE_OWNER && ! Authority::isModuleEnabled($id, $module)) {
                throw new ApiException(403, "{$moduleLabel} is not enabled for one of the selected companies.");
            }
        }
        $companies = Company::whereIn('id', $ids)->get()->keyBy('id');
        $scope = [];
        foreach ($ids as $id) {
            $c = $companies->get($id);
            $scope[$id] = $c ? trim("{$c->code} {$c->name}") : $id;
        }

        return $scope;
    }

    /** @return array<int, string> */
    protected function requestIdList(Request $request, string $key): array
    {
        $raw = $request->query($key);
        $parts = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_unique(array_filter(array_map('trim', $parts), fn ($v) => $v !== '')));
    }

    /** The short code at the front of a scope label ("C001 Acme Pte Ltd" -> "C001"). */
    protected function scopeCode(string $label): string
    {
        return strtok($label, ' ') ?: $label;
    }

    /**
     * A pick-list label: the name alone for one company, "Name (CODE)"
     * across several so two companies' same-named records can be told apart.
     */
    protected function scopedLabel(array $scope, string $name, ?string $companyId): string
    {
        return count($scope) > 1 ? "{$name} (".$this->scopeCode($scope[$companyId] ?? '').')' : $name;
    }

    /** Export columns: an Internal Company column first, but only when the report spans more than one. */
    protected function withScopeColumn(array $fields, array $scope): array
    {
        return count($scope) > 1 ? ['company_name', ...$fields] : $fields;
    }

    /**
     * Company / Individual choices across the scope, one list (a party can
     * be both customer and supplier).
     *
     * @return array<int, array{id: string, name: string}>
     */
    protected function scopedCompanyIndividuals(array $scope): array
    {
        return CompanyIndividual::whereIn('company_id', array_keys($scope))->orderBy('name')->get(['id', 'name', 'company_id'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $this->scopedLabel($scope, $c->name, $c->company_id)])->values()->all();
    }
}
