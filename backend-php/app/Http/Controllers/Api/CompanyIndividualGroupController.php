<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\CompanyIndividualGroup;
use App\Services\Audit;
use App\Services\Authority;
use Illuminate\Http\Request;

/**
 * CompanyIndividual Groups -- a lightweight tag linking separate
 * CompanyIndividual records that belong to the same group of
 * companies. Mirrors backend/app/routers/company_individual_groups.py
 * -- see App\Models\CompanyIndividualGroup for the design rationale
 * (each tagged customer stays its own full account; not a merged/
 * consolidated-billing hierarchy).
 */
class CompanyIndividualGroupController extends Controller
{
    private const MODULE = 'company_individual_management';

    private function groupOrFail(string $companyId, string $groupId): CompanyIndividualGroup
    {
        $group = CompanyIndividualGroup::find($groupId);
        if (! $group || $group->company_id !== $companyId) {
            throw new ApiException(404, 'Company / Individual group not found');
        }

        return $group;
    }

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $query = CompanyIndividualGroup::where('company_id', $user->company_id);
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return $query->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $data = $request->validate(['name' => 'required|string', 'description' => 'sometimes|nullable|string']);

        $group = CompanyIndividualGroup::create(array_merge($data, ['company_id' => $user->company_id]));

        Audit::record(
            'customer_group', $group->id, 'created', $user->id,
            details: "name={$data['name']}",
            newValue: ['name' => $data['name']],
        );

        return response()->json($group->fresh());
    }

    public function update(Request $request, string $groupId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $group = $this->groupOrFail($user->company_id, $groupId);
        $fields = $request->validate([
            'name' => 'sometimes|string',
            'description' => 'sometimes|nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $oldValue = [];
        $newValue = [];
        foreach (['name', 'description', 'is_active'] as $field) {
            if (! array_key_exists($field, $fields) || $group->{$field} == $fields[$field]) {
                continue;
            }
            $oldValue[$field] = $group->{$field};
            $newValue[$field] = $fields[$field];
            $group->{$field} = $fields[$field];
        }

        Audit::record('customer_group', $group->id, 'updated', $user->id, oldValue: $oldValue ?: null, newValue: $newValue ?: null);
        $group->save();

        return response()->json($group->fresh());
    }
}
