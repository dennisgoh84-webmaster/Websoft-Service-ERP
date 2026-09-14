<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\StockBrand;
use App\Models\StockCategory;
use App\Models\StockGroup;
use App\Models\StockModel;
use App\Models\StockUsage;
use App\Models\User;
use App\Services\Audit;
use App\Services\Authority;
use Illuminate\Http\Request;

/**
 * Stock setup master files: Categories, Groups, Brands + Models, and
 * Usages. Mirrors the corresponding half of
 * backend/app/routers/stock.py (`/api/stock/categories`, `/groups`,
 * `/brands`, `/brands/{id}/models`, `/models`, `/usages`).
 *
 * RBAC: every route here is gated by the `stock_master` module key --
 * VIEW to read, FULL to write -- identical to the Python router's own
 * require_module_access(...) calls.
 *
 * AUDIT TRAIL -- deliberate divergence from `backend/`, not an
 * oversight: backend/app/routers/stock.py writes NO audit entries at
 * all, for any of the six stock module keys. CLAUDE.md requires audit
 * trails on important operational transactions, and Event Logs is the
 * system-wide reader of that trail, so every create/update/state
 * change here records one. The entity_type/action strings are new
 * (there is no Python call site to match) and follow the existing
 * convention elsewhere in this codebase: a snake_case entity name plus
 * created/updated/activated/deactivated. Flagged in
 * docs/php-conversion-plan.md as a finding about `backend/`.
 */
class StockSetupController extends Controller
{
    private const MODULE = 'stock_master';

    // ── Categories ──────────────────────────────────────────────────

    public function indexCategories(Request $request)
    {
        $user = $this->viewer($request);

        return StockCategory::where('company_id', $user->company_id)
            ->orderBy('code')->get()->map(fn ($c) => $this->presentCoded($c))->values();
    }

    public function storeCategory(Request $request)
    {
        $user = $this->writer($request);
        $data = $this->validateCoded($request);

        $category = StockCategory::create($data + ['company_id' => $user->company_id]);
        Audit::record('stock_category', $category->id, 'created', $user->id,
            details: "{$category->code} {$category->name}",
            newValue: ['code' => $category->code, 'name' => $category->name]);

        return response()->json($this->presentCoded($category), 201);
    }

    public function updateCategory(Request $request, string $categoryId)
    {
        $user = $this->writer($request);
        $category = $this->codedOrFail(StockCategory::class, $user, $categoryId, 'Category not found');

        return response()->json($this->applyCodedUpdate($request, $category, $user, 'stock_category'));
    }

    public function toggleCategory(Request $request, string $categoryId)
    {
        $user = $this->writer($request);
        $category = $this->codedOrFail(StockCategory::class, $user, $categoryId, 'Category not found');

        return response()->json($this->applyToggle($category, $user, 'stock_category'));
    }

    // ── Groups ──────────────────────────────────────────────────────

    public function indexGroups(Request $request)
    {
        $user = $this->viewer($request);

        return StockGroup::where('company_id', $user->company_id)
            ->orderBy('code')->get()->map(fn ($g) => $this->presentCoded($g))->values();
    }

    public function storeGroup(Request $request)
    {
        $user = $this->writer($request);
        $data = $this->validateCoded($request);

        $group = StockGroup::create($data + ['company_id' => $user->company_id]);
        Audit::record('stock_group', $group->id, 'created', $user->id,
            details: "{$group->code} {$group->name}",
            newValue: ['code' => $group->code, 'name' => $group->name]);

        return response()->json($this->presentCoded($group), 201);
    }

    public function updateGroup(Request $request, string $groupId)
    {
        $user = $this->writer($request);
        $group = $this->codedOrFail(StockGroup::class, $user, $groupId, 'Group not found');

        return response()->json($this->applyCodedUpdate($request, $group, $user, 'stock_group'));
    }

    public function toggleGroup(Request $request, string $groupId)
    {
        $user = $this->writer($request);
        $group = $this->codedOrFail(StockGroup::class, $user, $groupId, 'Group not found');

        return response()->json($this->applyToggle($group, $user, 'stock_group'));
    }

    // ── Usages ──────────────────────────────────────────────────────

    public function indexUsages(Request $request)
    {
        $user = $this->viewer($request);

        return StockUsage::where('company_id', $user->company_id)
            ->orderBy('code')->get()->map(fn ($u) => $this->presentCoded($u))->values();
    }

    public function storeUsage(Request $request)
    {
        $user = $this->writer($request);
        $data = $this->validateCoded($request);

        $usage = StockUsage::create($data + ['company_id' => $user->company_id]);
        Audit::record('stock_usage', $usage->id, 'created', $user->id,
            details: "{$usage->code} {$usage->name}",
            newValue: ['code' => $usage->code, 'name' => $usage->name]);

        return response()->json($this->presentCoded($usage), 201);
    }

    public function updateUsage(Request $request, string $usageId)
    {
        $user = $this->writer($request);
        $usage = $this->codedOrFail(StockUsage::class, $user, $usageId, 'Usage not found');

        return response()->json($this->applyCodedUpdate($request, $usage, $user, 'stock_usage'));
    }

    public function toggleUsage(Request $request, string $usageId)
    {
        $user = $this->writer($request);
        $usage = $this->codedOrFail(StockUsage::class, $user, $usageId, 'Usage not found');

        return response()->json($this->applyToggle($usage, $user, 'stock_usage'));
    }

    // ── Brands ──────────────────────────────────────────────────────

    public function indexBrands(Request $request)
    {
        $user = $this->viewer($request);

        return StockBrand::where('company_id', $user->company_id)
            ->orderBy('name')->get()->map(fn ($b) => $this->presentBrand($b))->values();
    }

    public function storeBrand(Request $request)
    {
        $user = $this->writer($request);
        $data = $request->validate(['name' => 'required|string|max:200']);

        $brand = StockBrand::create(['company_id' => $user->company_id, 'name' => $data['name']]);
        Audit::record('stock_brand', $brand->id, 'created', $user->id,
            details: $brand->name, newValue: ['name' => $brand->name]);

        return response()->json($this->presentBrand($brand), 201);
    }

    public function updateBrand(Request $request, string $brandId)
    {
        $user = $this->writer($request);
        $brand = $this->brandOrFail($user, $brandId);
        $data = $request->validate(['name' => 'sometimes|required|string|max:200']);

        $old = ['name' => $brand->name];
        $brand->fill($data);
        if ($brand->isDirty()) {
            Audit::record('stock_brand', $brand->id, 'updated', $user->id,
                oldValue: $old, newValue: ['name' => $brand->name]);
        }
        $brand->save();

        return response()->json($this->presentBrand($brand->fresh()));
    }

    public function toggleBrand(Request $request, string $brandId)
    {
        $user = $this->writer($request);
        $brand = $this->brandOrFail($user, $brandId);
        $brand->is_active = ! $brand->is_active;
        Audit::record('stock_brand', $brand->id, $brand->is_active ? 'activated' : 'deactivated', $user->id,
            oldValue: ['is_active' => ! $brand->is_active], newValue: ['is_active' => $brand->is_active]);
        $brand->save();

        return response()->json($this->presentBrand($brand->fresh()));
    }

    // ── Models (children of a brand) ────────────────────────────────

    public function indexModels(Request $request, string $brandId)
    {
        $user = $this->viewer($request);
        // Verify the brand belongs to this company before listing its
        // models -- a StockModel has no company_id of its own.
        $this->brandOrFail($user, $brandId);

        return StockModel::where('brand_id', $brandId)
            ->orderBy('name')->get()->map(fn ($m) => $this->presentModel($m))->values();
    }

    public function storeModel(Request $request)
    {
        $user = $this->writer($request);
        $data = $request->validate([
            'brand_id' => 'required|uuid',
            'name' => 'required|string|max:200',
        ]);
        $brand = $this->brandOrFail($user, $data['brand_id']);

        $model = StockModel::create(['brand_id' => $brand->id, 'name' => $data['name']]);
        Audit::record('stock_model', $model->id, 'created', $user->id,
            details: "{$brand->name} {$model->name}",
            newValue: ['brand' => $brand->name, 'name' => $model->name]);

        return response()->json($this->presentModel($model), 201);
    }

    public function updateModel(Request $request, string $modelId)
    {
        $user = $this->writer($request);
        $model = $this->modelOrFail($user, $modelId);
        $data = $request->validate([
            'brand_id' => 'sometimes|required|uuid',
            'name' => 'sometimes|required|string|max:200',
        ]);
        if (isset($data['brand_id'])) {
            // Re-parenting is only ever allowed within this company.
            $this->brandOrFail($user, $data['brand_id']);
        }

        $old = ['brand_id' => $model->brand_id, 'name' => $model->name];
        $model->fill($data);
        if ($model->isDirty()) {
            Audit::record('stock_model', $model->id, 'updated', $user->id,
                oldValue: $old, newValue: ['brand_id' => $model->brand_id, 'name' => $model->name]);
        }
        $model->save();

        return response()->json($this->presentModel($model->fresh()));
    }

    public function toggleModel(Request $request, string $modelId)
    {
        $user = $this->writer($request);
        $model = $this->modelOrFail($user, $modelId);
        $model->is_active = ! $model->is_active;
        Audit::record('stock_model', $model->id, $model->is_active ? 'activated' : 'deactivated', $user->id,
            oldValue: ['is_active' => ! $model->is_active], newValue: ['is_active' => $model->is_active]);
        $model->save();

        return response()->json($this->presentModel($model->fresh()));
    }

    // ── Shared helpers ──────────────────────────────────────────────

    private function viewer(Request $request): User
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $user;
    }

    private function writer(Request $request): User
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        return $user;
    }

    /** @return array{code: string, name: string} */
    private function validateCoded(Request $request): array
    {
        return $request->validate([
            'code' => 'required|string|max:30',
            'name' => 'required|string|max:200',
        ]);
    }

    /**
     * @param  class-string<StockCategory|StockGroup|StockUsage>  $modelClass
     * @return StockCategory|StockGroup|StockUsage
     */
    private function codedOrFail(string $modelClass, User $user, string $id, string $message)
    {
        $row = $modelClass::find($id);
        if (! $row || $row->company_id !== $user->company_id) {
            throw new ApiException(404, $message);
        }

        return $row;
    }

    /**
     * PATCH on a code/name setup master. Both fields are `sometimes`
     * here, mirroring the Python route's `model_dump(exclude_unset=True)`
     * partial-update loop.
     *
     * @param  StockCategory|StockGroup|StockUsage  $row
     * @return array<string, mixed>
     */
    private function applyCodedUpdate(Request $request, $row, User $user, string $entityType): array
    {
        $data = $request->validate([
            'code' => 'sometimes|required|string|max:30',
            'name' => 'sometimes|required|string|max:200',
        ]);

        $old = ['code' => $row->code, 'name' => $row->name];
        $row->fill($data);
        if ($row->isDirty()) {
            Audit::record($entityType, $row->id, 'updated', $user->id,
                oldValue: $old, newValue: ['code' => $row->code, 'name' => $row->name]);
        }
        $row->save();

        return $this->presentCoded($row->fresh());
    }

    /**
     * @param  StockCategory|StockGroup|StockUsage  $row
     * @return array<string, mixed>
     */
    private function applyToggle($row, User $user, string $entityType): array
    {
        $row->is_active = ! $row->is_active;
        Audit::record($entityType, $row->id, $row->is_active ? 'activated' : 'deactivated', $user->id,
            oldValue: ['is_active' => ! $row->is_active], newValue: ['is_active' => $row->is_active]);
        $row->save();

        return $this->presentCoded($row->fresh());
    }

    private function brandOrFail(User $user, string $brandId): StockBrand
    {
        $brand = StockBrand::find($brandId);
        if (! $brand || $brand->company_id !== $user->company_id) {
            throw new ApiException(404, 'Brand not found');
        }

        return $brand;
    }

    private function modelOrFail(User $user, string $modelId): StockModel
    {
        $model = StockModel::join('stock_brands', 'stock_models.brand_id', '=', 'stock_brands.id')
            ->where('stock_models.id', $modelId)
            ->where('stock_brands.company_id', $user->company_id)
            ->select('stock_models.*')
            ->first();
        if (! $model) {
            throw new ApiException(404, 'Model not found');
        }

        return $model;
    }

    /**
     * @param  StockCategory|StockGroup|StockUsage  $row
     * @return array<string, mixed>
     */
    private function presentCoded($row): array
    {
        return [
            'id' => $row->id,
            'company_id' => $row->company_id,
            'code' => $row->code,
            'name' => $row->name,
            'is_active' => $row->is_active,
            'created_at' => optional($row->created_at)->toJSON(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentBrand(StockBrand $brand): array
    {
        return [
            'id' => $brand->id,
            'company_id' => $brand->company_id,
            'name' => $brand->name,
            'is_active' => $brand->is_active,
            'created_at' => optional($brand->created_at)->toJSON(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentModel(StockModel $model): array
    {
        return [
            'id' => $model->id,
            'brand_id' => $model->brand_id,
            'name' => $model->name,
            'is_active' => $model->is_active,
            'created_at' => optional($model->created_at)->toJSON(),
        ];
    }
}
