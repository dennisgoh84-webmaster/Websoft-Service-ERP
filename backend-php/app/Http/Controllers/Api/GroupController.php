<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Concerns\SendsExports;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\UserCompanyAccess;
use App\Services\Audit;
use App\Services\Authority;
use Illuminate\Http\Request;

/**
 * Group Authority admin API. Mirrors backend/app/routers/groups.py --
 * see App\Models\Group / GroupModuleAuthority for the design rationale
 * and App\Services\Authority for enforcement.
 */
class GroupController extends Controller
{
    use SendsExports;

    /** @var array<int, string> */
    private const EXPORT_FIELDS = ['name', 'description', 'member_count'];

    private const MODULE = 'core_administration';

    private function groupOrFail(string $groupId): Group
    {
        $group = Group::with('authorities')->find($groupId);
        if (! $group) {
            throw new ApiException(404, 'Group not found');
        }

        return $group;
    }

    /** Staff holding this Group. Group is per company, so membership lives on UserCompanyAccess. */
    private function memberCount(string $groupId): int
    {
        return UserCompanyAccess::where('group_id', $groupId)->count();
    }

    private function present(Group $group): array
    {
        return [
            'id' => $group->id,
            'company_id' => $group->company_id,
            'name' => $group->name,
            'description' => $group->description,
            'created_at' => $group->created_at,
            'member_count' => $this->memberCount($group->id),
            'authorities' => $group->authorities->map(fn ($a) => [
                'module_key' => $a->module_key,
                'access_level' => $a->access_level,
            ])->all(),
        ];
    }

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::FULL);

        $data = $request->validate(['name' => 'required|string', 'description' => 'sometimes|nullable|string']);

        $group = Group::create([
            'company_id' => $user->company_id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        Audit::record(
            'group', $group->id, 'created', $user->id,
            details: "name={$data['name']}",
            newValue: ['name' => $data['name'], 'description' => $data['description'] ?? null],
        );

        return response()->json($this->present($group->fresh('authorities')));
    }

    /**
     * Groups of the company you are working in. `company_id` lists
     * another company's groups instead (Staff Master assigning a Group
     * in a company other than the active one).
     */
    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::VIEW);

        $companyId = $request->query('company_id');
        if ($companyId !== null && ! in_array($companyId, CompanyController::accessibleCompanyIds($user), true)) {
            throw new ApiException(403, 'You do not have access to this company.');
        }
        $targetCompanyId = $companyId ?? $user->company_id;

        $groups = Group::with('authorities')->where('company_id', $targetCompanyId)->orderBy('name')->get();

        return $groups->map(fn ($g) => $this->present($g))->values();
    }

    /**
     * Which company's groups this request is for -- the caller's own
     * unless they name another they have access to. Shared with the
     * exports so they can never read a company the list would refuse.
     */
    private function targetCompanyId(Request $request, $user): string
    {
        $companyId = $request->query('company_id');
        if ($companyId !== null && ! in_array($companyId, CompanyController::accessibleCompanyIds($user), true)) {
            throw new ApiException(403, 'You do not have access to this company.');
        }

        return $companyId ?? $user->company_id;
    }

    /** @return array<int, array<string, mixed>> */
    private function exportRows(Request $request, $user): array
    {
        return Group::where('company_id', $this->targetCompanyId($request, $user))
            ->orderBy('name')->get()->map(fn (Group $g) => [
                'name' => $g->name,
                'description' => $g->description ?? '',
                'member_count' => $this->memberCount($g->id),
            ])->all();
    }

    public function exportCsv(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::VIEW);

        return $this->csvResponse(self::EXPORT_FIELDS, $this->exportRows($request, $user), 'groups.csv');
    }

    public function exportExcel(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::VIEW);

        return $this->xlsxResponse(self::EXPORT_FIELDS, $this->exportRows($request, $user), 'Groups', 'groups.xlsx');
    }

    public function show(Request $request, string $groupId)
    {
        Authority::requireModuleAccess(Authenticate::user($request), self::MODULE, GroupModuleAuthority::VIEW);

        return response()->json($this->present($this->groupOrFail($groupId)));
    }

    public function update(Request $request, string $groupId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::FULL);

        $group = $this->groupOrFail($groupId);
        $fields = $request->validate(['name' => 'sometimes|string', 'description' => 'sometimes|nullable|string']);

        $oldValue = [];
        $newValue = [];
        if (array_key_exists('name', $fields) && $fields['name'] !== $group->name) {
            $oldValue['name'] = $group->name;
            $newValue['name'] = $fields['name'];
            $group->name = $fields['name'];
        }
        if (array_key_exists('description', $fields) && $fields['description'] !== $group->description) {
            $oldValue['description'] = $group->description;
            $newValue['description'] = $fields['description'];
            $group->description = $fields['description'];
        }

        Audit::record('group', $group->id, 'updated', $user->id, oldValue: $oldValue ?: null, newValue: $newValue ?: null);
        $group->save();

        return response()->json($this->present($group->fresh('authorities')));
    }

    public function destroy(Request $request, string $groupId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::FULL);

        $group = $this->groupOrFail($groupId);
        if ($this->memberCount($group->id) > 0) {
            throw new ApiException(409, 'Cannot delete a Group that still has staff assigned to it. Reassign those staff to another Group first.');
        }

        Audit::record(
            'group', $group->id, 'deleted', $user->id,
            details: "name={$group->name}",
            oldValue: ['name' => $group->name, 'description' => $group->description],
        );
        $group->delete();

        return response()->noContent();
    }

    public function setAuthorities(Request $request, string $groupId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::FULL);

        $group = $this->groupOrFail($groupId);
        $data = $request->validate([
            'authorities' => 'required|array',
            'authorities.*.module_key' => 'required|string',
            'authorities.*.access_level' => 'required|in:none,view,edit,full',
        ]);

        $validModuleKeys = ModuleCatalog::pluck('key')->all();
        $existing = $group->authorities->keyBy('module_key');

        $oldValue = [];
        $newValue = [];
        foreach ($data['authorities'] as $entry) {
            if (! in_array($entry['module_key'], $validModuleKeys, true)) {
                throw new ApiException(400, "Unknown module '{$entry['module_key']}'");
            }
            $row = $existing->get($entry['module_key']);
            $priorLevel = $row?->access_level ?? GroupModuleAuthority::NONE;
            if ($priorLevel !== $entry['access_level']) {
                $oldValue[$entry['module_key']] = $priorLevel;
                $newValue[$entry['module_key']] = $entry['access_level'];
            }
            if ($row) {
                $row->update(['access_level' => $entry['access_level']]);
            } else {
                GroupModuleAuthority::create([
                    'group_id' => $group->id,
                    'module_key' => $entry['module_key'],
                    'access_level' => $entry['access_level'],
                ]);
            }
        }

        Audit::record(
            'group', $group->id, 'authorities_updated', $user->id,
            details: collect($data['authorities'])->map(fn ($e) => "{$e['module_key']}={$e['access_level']}")->implode(', '),
            oldValue: $oldValue ?: null,
            newValue: $newValue ?: null,
        );

        return response()->json($this->present($this->groupOrFail($groupId)));
    }
}
