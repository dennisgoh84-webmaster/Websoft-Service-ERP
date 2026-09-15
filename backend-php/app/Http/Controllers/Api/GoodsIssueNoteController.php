<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Exceptions\InventoryRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\GoodsIssueNote;
use App\Models\GoodsIssueNoteLine;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\InventoryService;
use App\Services\StockDocumentGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Goods Issue Note (GIN): stock leaving the warehouse to a job order,
 * a customer, or internal use. Requested by Dennis 2026-09-15.
 *
 * NEW -- `backend/` has no equivalent, so this is not a conversion.
 * Deliberately shaped like the Goods Return Note it sits beside
 * (draft -> confirm, count-based numbering, the same guards), so the
 * stock module keeps one document pattern rather than two.
 *
 * RBAC: `goods_issue_note` -- VIEW to list, FULL to create/confirm.
 *
 * A line carries NO unit cost on entry: stock leaves at the item's
 * weighted average cost, which the issue never moves, so a cost
 * supplied here would be a second and contradictory source of truth.
 * Confirming stamps the average that actually applied onto the line.
 *
 * Confirming refuses outright if the warehouse does not hold enough --
 * the whole document rolls back rather than issuing part of it, and no
 * on-hand quantity is ever driven negative.
 *
 * KNOWN GAP, consistent with every other stock document: a confirmed
 * GIN posts nothing to the General Ledger. Confirmed with Dennis
 * 2026-09-15 -- stock stays a sub-ledger, so there is no COGS entry.
 */
class GoodsIssueNoteController extends Controller
{
    private const MODULE = 'goods_issue_note';

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return GoodsIssueNote::with('lines')
            ->where('company_id', $user->company_id)
            ->orderByDesc('created_at')->get()
            ->map(fn (GoodsIssueNote $g) => $this->present($g))->values();
    }

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $data = $request->validate([
            'warehouse_id' => 'required|uuid',
            'customer_id' => 'sometimes|nullable|uuid',
            'job_order_id' => 'sometimes|nullable|uuid',
            'issue_date' => 'sometimes|nullable|date',
            'reason' => 'sometimes|nullable|string',
            'notes' => 'sometimes|nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.stock_item_id' => 'required|uuid',
            'lines.*.quantity' => 'required|integer|gt:0',
            'lines.*.notes' => 'sometimes|nullable|string',
        ]);

        StockDocumentGuard::assertWarehouse($user, $data['warehouse_id']);
        StockDocumentGuard::assertStockItems($user, $data['lines']);
        if (! empty($data['customer_id'])) {
            StockDocumentGuard::assertCustomer($user, $data['customer_id']);
        }

        $gin = DB::transaction(function () use ($user, $data) {
            $gin = GoodsIssueNote::create([
                'company_id' => $user->company_id,
                'gin_number' => $this->nextGinNumber($user->company_id),
                'warehouse_id' => $data['warehouse_id'],
                'customer_id' => $data['customer_id'] ?? null,
                'job_order_id' => $data['job_order_id'] ?? null,
                'issue_date' => $data['issue_date'] ?? now(),
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($data['lines'] as $line) {
                GoodsIssueNoteLine::create([
                    'gin_id' => $gin->id,
                    'stock_item_id' => $line['stock_item_id'],
                    'quantity' => $line['quantity'],
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            Audit::record('goods_issue_note', $gin->id, 'created', $user->id,
                details: $gin->gin_number,
                newValue: ['gin_number' => $gin->gin_number, 'lines' => count($data['lines'])]);

            return $gin;
        });

        return response()->json($this->present($gin->fresh('lines')), 201);
    }

    public function confirm(Request $request, string $ginId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $gin = GoodsIssueNote::with('lines')->find($ginId);
        if (! $gin || $gin->company_id !== $user->company_id) {
            throw new ApiException(404, 'GIN not found');
        }

        try {
            DB::transaction(function () use ($gin, $user) {
                InventoryService::confirmGin($gin, $user->id);
                $gin->save();
                Audit::record('goods_issue_note', $gin->id, 'confirmed', $user->id,
                    details: $gin->gin_number,
                    oldValue: ['status' => GoodsIssueNote::STATUS_DRAFT],
                    newValue: ['status' => $gin->status]);
            });
        } catch (InventoryRuleViolation $e) {
            // Insufficient stock arrives here, so the whole document is
            // rolled back and refused with the shortfall spelled out.
            throw new ApiException(400, $e->getMessage());
        }

        return response()->json($this->present($gin->fresh('lines')));
    }

    /** GIN-00001, ... -- the same count-based scheme every stock document uses. */
    private function nextGinNumber(string $companyId): string
    {
        $count = GoodsIssueNote::where('company_id', $companyId)->count();

        return 'GIN-'.str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
    }

    /** @return array<string, mixed> */
    private function present(GoodsIssueNote $gin): array
    {
        return [
            'id' => $gin->id,
            'company_id' => $gin->company_id,
            'gin_number' => $gin->gin_number,
            'warehouse_id' => $gin->warehouse_id,
            'customer_id' => $gin->customer_id,
            'job_order_id' => $gin->job_order_id,
            'issue_date' => optional($gin->issue_date)->toJSON(),
            'reason' => $gin->reason,
            'status' => $gin->status,
            'notes' => $gin->notes,
            'created_by' => $gin->created_by,
            'created_at' => optional($gin->created_at)->toJSON(),
            'lines' => $gin->lines->map(fn (GoodsIssueNoteLine $l) => [
                'id' => $l->id,
                'stock_item_id' => $l->stock_item_id,
                'quantity' => $l->quantity,
                // Null until the document is confirmed.
                'unit_cost' => $l->unit_cost === null ? null : (float) $l->unit_cost,
                'total_cost' => $l->total_cost === null ? null : (float) $l->total_cost,
                'notes' => $l->notes,
            ])->values(),
        ];
    }
}
