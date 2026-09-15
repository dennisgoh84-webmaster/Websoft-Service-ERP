<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\GroupModuleAuthority;
use App\Models\SetupListItem;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\Exports;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Setup Lists -- Nationality, Country, State, Area Code, Currency,
 * Industry. Mirrors backend/app/routers/setup_lists.py 1:1.
 *
 * GLOBAL reference data, NOT company-scoped (see App\Models\SetupListItem):
 * every company reads and writes one shared list per list_type. That
 * makes the usual "another company's row is a 404" case inapplicable
 * here -- its opposite is the point of the module, and is what the
 * tests pin instead. Gated on core_administration, so it is still only
 * editable by someone with rights to it.
 */
class SetupListController extends Controller
{
    private const MODULE = 'core_administration';

    /** @var array<int, string> */
    private const EXPORT_FIELDS = ['list_type', 'code', 'name', 'parent_code', 'sort_order', 'is_active'];

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::VIEW);

        return response()->json(
            $this->filtered($request)->map(fn (SetupListItem $i) => $this->out($i))
        );
    }

    public function exportCsv(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::VIEW);

        return response(Exports::rowsToCsv(self::EXPORT_FIELDS, $this->exportRows($request)), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename=setup-lists.csv',
        ]);
    }

    public function exportExcel(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::VIEW);

        $data = Exports::rowsToExcel(self::EXPORT_FIELDS, $this->exportRows($request), 'Setup Lists');

        return response($data, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename=setup-lists.xlsx',
        ]);
    }

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::EDIT);

        // Mirrors Python's SetupListItemCreate: no is_active on create.
        $data = $request->validate([
            'list_type' => 'required|string|in:'.implode(',', SetupListItem::TYPES),
            'code' => 'required|string|min:1|max:20',
            'name' => 'required|string|min:1|max:150',
            'parent_code' => 'sometimes|nullable|string|max:20',
            'sort_order' => 'sometimes|integer',
        ]);

        // Uniqueness is (list_type, code) -- the same code may exist
        // under two different list types.
        $exists = SetupListItem::where('list_type', $data['list_type'])->where('code', $data['code'])->first();
        if ($exists) {
            throw new ApiException(409, "{$data['list_type']} code {$data['code']} already exists.");
        }

        $item = DB::transaction(function () use ($data, $user) {
            $item = SetupListItem::create($data);

            Audit::record(
                entityType: 'setup_list_item',
                entityId: $item->id,
                action: 'created',
                actorUserId: $user->id,
                details: "{$data['list_type']}: {$data['code']} {$data['name']}",
                newValue: [
                    'list_type' => $data['list_type'],
                    'code' => $data['code'],
                    'name' => $data['name'],
                ],
            );

            return $item;
        });

        return response()->json($this->out($item->refresh()));
    }

    public function update(Request $request, string $itemId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::EDIT);

        // list_type is absent from Python's SetupListItemUpdate: an item
        // cannot be moved between lists, since (list_type, code) is its
        // identity.
        $data = $request->validate([
            'code' => 'sometimes|string|min:1|max:20',
            'name' => 'sometimes|string|min:1|max:150',
            'parent_code' => 'sometimes|nullable|string|max:20',
            'sort_order' => 'sometimes|integer',
            'is_active' => 'sometimes|boolean',
        ]);

        $item = SetupListItem::find($itemId);
        if (! $item) {
            throw new ApiException(404, 'Setup list item not found');
        }

        DB::transaction(function () use ($data, $item, $user) {
            $oldValue = [];
            $newValue = [];
            foreach (['code', 'name', 'parent_code', 'sort_order', 'is_active'] as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }
                $old = $item->$field;
                $new = $data[$field];
                if ($old == $new) {
                    continue;
                }
                // Python records these two raw, not stringified.
                $oldValue[$field] = $old;
                $newValue[$field] = $new;
                $item->$field = $new;
            }
            $item->save();

            Audit::record(
                entityType: 'setup_list_item',
                entityId: $item->id,
                action: 'updated',
                actorUserId: $user->id,
                details: "{$item->list_type}: {$item->code} {$item->name}",
                oldValue: $oldValue ?: null,
                newValue: $newValue ?: null,
            );
        });

        return response()->json($this->out($item->refresh()));
    }

    /** @return Collection<int, SetupListItem> */
    private function filtered(Request $request)
    {
        $query = SetupListItem::query();
        if ($listType = $request->query('list_type')) {
            $query->where('list_type', $listType);
        }
        // A State or City list narrowed to one Country -- what the
        // Company/Individual address pickers and the State/City setup
        // screens ask for.
        if ($parentCode = $request->query('parent_code')) {
            $query->where('parent_code', $parentCode);
        }
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return $query->orderBy('list_type')->orderBy('sort_order')->orderBy('code')->get();
    }

    /** @return array<int, array<string, mixed>> */
    private function exportRows(Request $request): array
    {
        return $this->filtered($request)->map(fn (SetupListItem $i) => [
            'list_type' => $i->list_type,
            'code' => $i->code,
            'name' => $i->name,
            // Python exports null parent_code as an empty string.
            'parent_code' => $i->parent_code ?? '',
            'sort_order' => $i->sort_order,
            'is_active' => $i->is_active,
        ])->all();
    }

    /** @return array<string, mixed> */
    private function out(SetupListItem $i): array
    {
        return [
            'id' => $i->id,
            'list_type' => $i->list_type,
            'code' => $i->code,
            'name' => $i->name,
            'parent_code' => $i->parent_code,
            'sort_order' => $i->sort_order,
            'is_active' => $i->is_active,
        ];
    }
}
