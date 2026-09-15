<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Product;
use App\Models\StockBrand;
use App\Models\StockCategory;
use App\Models\StockGroup;
use App\Models\StockItem;
use App\Models\StockItemAttachment;
use App\Models\StockLevel;
use App\Models\StockModel;
use App\Models\StockUsage;
use App\Models\User;
use App\Services\Audit;
use App\Services\Authority;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Stock Master: the stock item itself, its picture/document
 * attachments, and the per-warehouse stock levels the Stock Master and
 * Stock Item Detail screens read. Mirrors the `/api/stock/items`,
 * `/api/stock/items/{id}/attachments` and `/api/stock/levels` half of
 * backend/app/routers/stock.py.
 *
 * RBAC: `stock_master` throughout -- VIEW to read (including an
 * attachment download), FULL to write -- identical to the Python
 * router.
 *
 * Stock levels are READ-ONLY here. A quantity or an average cost is
 * only ever changed by App\Services\InventoryService, through a GRN,
 * GTN, GRTN or an approved Stock Adjustment, so the `stock_movements`
 * ledger always explains the balance.
 *
 * BUG FOUND AND FIXED (see docs/php-conversion-plan.md): the Python
 * PATCH route types its body as `StockItemCreate`, whose `code` and
 * `name` are REQUIRED and which has no `is_active` field, so
 * `StockItemDetailPage.tsx`'s Activate/Deactivate button
 * (`updateStockItem(id, { is_active: !item.is_active })`) can only
 * ever 422 against `backend/`. Every field is optional here (what
 * Python's own `model_dump(exclude_unset=True)` loop was written for)
 * and `is_active` is accepted.
 */
class StockItemController extends Controller
{
    private const MODULE = 'stock_master';

    /**
     * PRAGMATIC DEFAULT, not a confirmed rule (called out here rather
     * than silently assumed, per CLAUDE.md): the Python route puts no
     * size limit on an item picture/document at all, which would let a
     * single upload fill the disk. 10 MB is set here as a sane
     * starting ceiling; change it if Dennis wants a different one.
     */
    private const MAX_ATTACHMENT_KB = 10240;

    // ── Stock Items ─────────────────────────────────────────────────

    public function index(Request $request)
    {
        $user = $this->viewer($request);

        return StockItem::with($this->lookupRelations())
            ->where('company_id', $user->company_id)
            ->orderBy('code')->get()
            ->map(fn (StockItem $i) => $this->present($i))->values();
    }

    public function show(Request $request, string $itemId)
    {
        $user = $this->viewer($request);

        return response()->json($this->present($this->itemOrFail($user, $itemId)));
    }

    public function store(Request $request)
    {
        $user = $this->writer($request);
        $data = $request->validate($this->itemRules(required: true));
        $this->assertLookupsBelongToCompany($user, $data);

        $item = StockItem::create($data + ['company_id' => $user->company_id]);
        Audit::record('stock_item', $item->id, 'created', $user->id,
            details: "{$item->code} {$item->name}",
            newValue: ['code' => $item->code, 'name' => $item->name, 'unit_of_measure' => $item->unit_of_measure]);

        return response()->json($this->present($this->reload($item)), 201);
    }

    public function update(Request $request, string $itemId)
    {
        $user = $this->writer($request);
        $item = $this->itemOrFail($user, $itemId);
        $data = $request->validate($this->itemRules(required: false));
        $this->assertLookupsBelongToCompany($user, $data);

        $old = $this->auditSnapshot($item);
        $item->fill($data);
        if ($item->isDirty()) {
            $action = $item->isDirty('is_active') && count($item->getDirty()) === 1
                ? ($item->is_active ? 'activated' : 'deactivated')
                : 'updated';
            Audit::record('stock_item', $item->id, $action, $user->id,
                oldValue: $old, newValue: $this->auditSnapshot($item));
        }
        $item->save();

        return response()->json($this->present($this->reload($item)));
    }

    // ── Attachments ─────────────────────────────────────────────────

    public function uploadAttachment(Request $request, string $itemId)
    {
        $user = $this->writer($request);
        $item = $this->itemOrFail($user, $itemId);

        $request->validate(['file' => 'required|file|max:'.self::MAX_ATTACHMENT_KB]);
        $file = $request->file('file');

        $attachmentId = (string) Str::uuid();
        // Stored under this attachment's own uuid plus the uploaded
        // extension -- never the user-supplied filename, which is kept
        // only as display/download text.
        $extension = strtolower($file->getClientOriginalExtension());
        $stored = $extension === '' ? $attachmentId : "{$attachmentId}.{$extension}";
        $file->move($this->attachmentDir($user->company_id, $item->id), $stored);

        $attachment = StockItemAttachment::create([
            'id' => $attachmentId,
            'stock_item_id' => $item->id,
            'filename' => $file->getClientOriginalName() ?: 'file',
            'stored_filename' => $stored,
            'content_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
        ]);

        // The audit trail records that a file was attached -- never the
        // file content itself, same rule as the PDPA agreement upload.
        Audit::record('stock_item', $item->id, 'attachment_uploaded', $user->id,
            details: $attachment->filename, newValue: ['filename' => $attachment->filename]);

        return response()->json($this->presentAttachment($attachment), 201);
    }

    public function deleteAttachment(Request $request, string $itemId, string $attachmentId)
    {
        $user = $this->writer($request);
        $attachment = $this->attachmentOrFail($user, $itemId, $attachmentId);

        // The file on disk is removed with its row -- an item picture
        // is not a business or financial record, so CLAUDE.md's "never
        // permanently delete" rule does not apply to it. Same choice
        // the Python route makes.
        $path = $this->attachmentDir($user->company_id, $itemId).'/'.$attachment->stored_filename;
        if (is_file($path)) {
            @unlink($path);
        }

        Audit::record('stock_item', $itemId, 'attachment_removed', $user->id,
            details: $attachment->filename, oldValue: ['filename' => $attachment->filename]);
        $attachment->delete();

        return response()->noContent();
    }

    public function downloadAttachment(Request $request, string $itemId, string $attachmentId)
    {
        $user = $this->viewer($request);
        $attachment = $this->attachmentOrFail($user, $itemId, $attachmentId);

        $path = $this->attachmentDir($user->company_id, $itemId).'/'.$attachment->stored_filename;
        if (! is_file($path)) {
            throw new ApiException(404, 'File not found on disk');
        }

        return response()->download($path, $attachment->filename, [
            'Content-Type' => $attachment->content_type ?: 'application/octet-stream',
        ]);
    }

    // ── Stock Levels (read-only) ────────────────────────────────────

    public function levels(Request $request)
    {
        $user = $this->viewer($request);

        $query = StockLevel::query()
            ->join('stock_items', 'stock_levels.stock_item_id', '=', 'stock_items.id')
            ->join('warehouses', 'stock_levels.warehouse_id', '=', 'warehouses.id')
            ->where('stock_levels.company_id', $user->company_id)
            ->select(
                'stock_levels.*',
                // Held once per item across all locations (2026-09-15),
                // so every warehouse row reports the same unit cost.
                'stock_items.avg_cost',
                'stock_items.code as item_code',
                'stock_items.name as item_name',
                'warehouses.code as warehouse_code',
                'warehouses.name as warehouse_name',
            );

        if ($request->filled('warehouse_id')) {
            $query->where('stock_levels.warehouse_id', $request->query('warehouse_id'));
        }

        return $query->orderBy('stock_items.code')->orderBy('warehouses.code')->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'stock_item_id' => $row->stock_item_id,
                'warehouse_id' => $row->warehouse_id,
                'quantity' => (int) $row->quantity,
                // 4dp at the JSON boundary -- a stock average cost is
                // Numeric(14, 4), not 2dp money.
                'avg_cost' => (float) $row->avg_cost,
                'item_code' => $row->item_code,
                'item_name' => $row->item_name,
                'warehouse_code' => $row->warehouse_code,
                'warehouse_name' => $row->warehouse_name,
            ])->values();
    }

    // ── Helpers ─────────────────────────────────────────────────────

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

    /** @return array<string, string> */
    private function itemRules(bool $required): array
    {
        $req = $required ? 'required' : 'sometimes|required';

        return [
            'code' => "{$req}|string|max:50",
            'name' => "{$req}|string|max:255",
            'description' => 'sometimes|nullable|string',
            'category' => 'sometimes|nullable|string|max:100',
            'unit_of_measure' => 'sometimes|string|max:30',
            'product_id' => 'sometimes|nullable|uuid',
            'reorder_level' => 'sometimes|integer',
            'category_id' => 'sometimes|nullable|uuid',
            'group_id' => 'sometimes|nullable|uuid',
            'brand_id' => 'sometimes|nullable|uuid',
            'model_id' => 'sometimes|nullable|uuid',
            'usage_id' => 'sometimes|nullable|uuid',
            'barcode' => 'sometimes|nullable|string|max:100',
            'part_number' => 'sometimes|nullable|string|max:100',
            'invoice_description' => 'sometimes|nullable|string',
            'memo' => 'sometimes|nullable|string',
            'notes' => 'sometimes|nullable|string',
            'dimensions' => 'sometimes|nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
        ];
    }

    /**
     * Multi-company scoping: a lookup FK may only point at this
     * company's own setup master. The Python router does not check
     * this (it assigns the raw uuid straight through), but a cross-
     * company FK would leak another company's category/brand name into
     * this company's Stock Master screen through the joined display
     * names -- so it is refused here rather than silently stored.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertLookupsBelongToCompany(User $user, array $data): void
    {
        $checks = [
            'category_id' => [StockCategory::class, 'Category not found'],
            'group_id' => [StockGroup::class, 'Group not found'],
            'usage_id' => [StockUsage::class, 'Usage not found'],
            'brand_id' => [StockBrand::class, 'Brand not found'],
            'product_id' => [Product::class, 'Product not found'],
        ];
        foreach ($checks as $field => [$class, $message]) {
            if (empty($data[$field])) {
                continue;
            }
            $row = $class::find($data[$field]);
            if (! $row || $row->company_id !== $user->company_id) {
                throw new ApiException(404, $message);
            }
        }

        // A StockModel has no company_id -- scope it through its brand.
        if (! empty($data['model_id'])) {
            $model = StockModel::with('brand')->find($data['model_id']);
            if (! $model || ! $model->brand || $model->brand->company_id !== $user->company_id) {
                throw new ApiException(404, 'Model not found');
            }
        }
    }

    private function itemOrFail(User $user, string $itemId): StockItem
    {
        $item = StockItem::with($this->lookupRelations())->find($itemId);
        if (! $item || $item->company_id !== $user->company_id) {
            throw new ApiException(404, 'Stock item not found');
        }

        return $item;
    }

    private function attachmentOrFail(User $user, string $itemId, string $attachmentId): StockItemAttachment
    {
        // The item lookup is what enforces company scoping -- an
        // attachment row carries no company_id of its own.
        $this->itemOrFail($user, $itemId);
        $attachment = StockItemAttachment::where('id', $attachmentId)->where('stock_item_id', $itemId)->first();
        if (! $attachment) {
            throw new ApiException(404, 'Attachment not found');
        }

        return $attachment;
    }

    private function attachmentDir(string $companyId, string $itemId): string
    {
        $dir = rtrim((string) config('websoft.uploads_dir'), '/')."/stock/{$companyId}/{$itemId}";
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    private function reload(StockItem $item): StockItem
    {
        return $item->fresh($this->lookupRelations());
    }

    /** @return list<string> */
    private function lookupRelations(): array
    {
        return ['stockCategory', 'stockGroup', 'stockBrand', 'stockModel', 'stockUsage', 'attachments'];
    }

    /** @return array<string, mixed> */
    private function auditSnapshot(StockItem $item): array
    {
        return [
            'code' => $item->code,
            'name' => $item->name,
            'unit_of_measure' => $item->unit_of_measure,
            'reorder_level' => $item->reorder_level,
            'is_active' => $item->is_active,
        ];
    }

    /** @return array<string, mixed> */
    private function present(StockItem $item): array
    {
        return [
            'id' => $item->id,
            'company_id' => $item->company_id,
            'code' => $item->code,
            'name' => $item->name,
            'description' => $item->description,
            'category' => $item->category,
            'unit_of_measure' => $item->unit_of_measure,
            'product_id' => $item->product_id,
            'reorder_level' => (int) $item->reorder_level,
            'category_id' => $item->category_id,
            'group_id' => $item->group_id,
            'brand_id' => $item->brand_id,
            'model_id' => $item->model_id,
            'usage_id' => $item->usage_id,
            'barcode' => $item->barcode,
            'part_number' => $item->part_number,
            'invoice_description' => $item->invoice_description,
            'memo' => $item->memo,
            'notes' => $item->notes,
            'dimensions' => $item->dimensions,
            // Costing (2026-09-15): the weighted average cost of this
            // item across ALL locations and branches, and the extended
            // value of everything on hand at that average. Read-only --
            // both are maintained by App\Services\InventoryService and
            // are never settable through this controller.
            // 4dp for the unit cost (Numeric(14, 4)), 2dp for the value.
            'avg_cost' => (float) $item->avg_cost,
            'cost_value' => (float) $item->cost_value,
            // Joined display names, so the Stock Master list can show
            // the lookup text without a request per row.
            'category_name' => $item->stockCategory?->name,
            'group_name' => $item->stockGroup?->name,
            'brand_name' => $item->stockBrand?->name,
            'model_name' => $item->stockModel?->name,
            'usage_name' => $item->stockUsage?->name,
            'attachments' => $item->attachments->map(
                fn (StockItemAttachment $a) => $this->presentAttachment($a)
            )->values(),
            'is_active' => $item->is_active,
            'created_at' => optional($item->created_at)->toJSON(),
            'updated_at' => optional($item->updated_at)->toJSON(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentAttachment(StockItemAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'stock_item_id' => $attachment->stock_item_id,
            'filename' => $attachment->filename,
            'content_type' => $attachment->content_type,
            'file_size' => $attachment->file_size !== null ? (int) $attachment->file_size : null,
            'created_at' => optional($attachment->created_at)->toJSON(),
        ];
    }
}
