<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Exceptions\PayablesRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\CompanyIndividual;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\Numbering;
use App\Services\PayablesService;
use App\Services\Tax;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Purchase Orders. Mirrors the purchase-order half of
 * backend/app/routers/payables.py -- see App\Services\PayablesService
 * for the PUR-001/002/003 business logic this only orchestrates.
 *
 * NOT yet converted: CSV/Excel export, the .docx export and "Email
 * Purchase Order" endpoints (need the Documents module's mailer
 * wiring, same gap as Service Records/Invoices).
 */
class PurchaseOrderController extends Controller
{
    private const MODULE = 'accounts_payable';

    private function supplierOrFail(string $companyId, string $supplierId): CompanyIndividual
    {
        $supplier = CompanyIndividual::find($supplierId);
        if (! $supplier || $supplier->company_id !== $companyId || ! $supplier->is_supplier) {
            throw new ApiException(404, "Supplier not found -- check it exists and is marked 'Is Supplier' on the Company/Individual page.");
        }

        return $supplier;
    }

    private function poOrFail(string $companyId, string $poId): PurchaseOrder
    {
        $po = PurchaseOrder::find($poId);
        if (! $po || $po->company_id !== $companyId) {
            throw new ApiException(404, 'Purchase order not found');
        }

        return $po;
    }

    private function present(PurchaseOrder $po): array
    {
        $importedBill = SupplierInvoice::where('purchase_order_id', $po->id)->first();

        return [
            'id' => $po->id,
            'po_number' => $po->po_number,
            'supplier_id' => $po->supplier_id,
            'order_date' => optional($po->order_date)->toDateString(),
            'description' => $po->description,
            'amount_sgd' => (float) $po->amount_sgd,
            'gst_amount_sgd' => (float) $po->gst_amount_sgd,
            'total_amount_sgd' => (float) $po->total_amount_sgd,
            'status' => $po->status,
            'imported_bill_id' => $importedBill?->id,
            'imported_bill_number' => $importedBill?->bill_number,
        ];
    }

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $query = PurchaseOrder::where('company_id', $user->company_id);
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->query('supplier_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return $query->orderByDesc('order_date')->get()->map(fn (PurchaseOrder $po) => $this->present($po))->values();
    }

    public function show(Request $request, string $poId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return response()->json($this->present($this->poOrFail($user->company_id, $poId)));
    }

    /**
     * Raise a PO. GST is applied at the company's rate; whether it
     * then needs the owner's approval is PUR-001's value test.
     */
    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $data = $request->validate([
            'supplier_id' => 'required|uuid',
            'order_date' => 'required|date',
            'description' => 'required|string|min:1',
            'amount_sgd' => 'required|numeric|gt:0',
        ]);
        $this->supplierOrFail($user->company_id, $data['supplier_id']);

        $po = DB::transaction(function () use ($user, $data) {
            [$_taxCode, $_rate, $gst, $total] = Tax::applyGst($user->company_id, Money::of($data['amount_sgd']));
            $needsOwner = PayablesService::poNeedsOwnerApproval($user->company_id, $total);

            $po = PurchaseOrder::create([
                'company_id' => $user->company_id,
                'supplier_id' => $data['supplier_id'],
                'po_number' => Numbering::next($user->company_id, 'purchase_order'),
                'order_date' => $data['order_date'],
                'description' => $data['description'],
                'amount_sgd' => $data['amount_sgd'],
                'gst_amount_sgd' => $gst->toString(),
                'total_amount_sgd' => $total->toString(),
                'status' => $needsOwner ? PurchaseOrder::STATUS_PENDING_APPROVAL : PurchaseOrder::STATUS_DRAFT,
            ]);

            Audit::record(
                entityType: 'purchase_order',
                entityId: $po->id,
                action: 'created',
                actorUserId: $user->id,
                details: "{$po->po_number}: {$data['description']} SGD {$total->toString()}",
                newValue: ['po_number' => $po->po_number, 'total_sgd' => $total->toString(), 'status' => $po->status],
            );

            return $po;
        });

        return response()->json($this->present($po));
    }

    /** PUR-001: value-based approval -- the "confirm" step before a PO can be imported to AP. */
    public function approve(Request $request, string $poId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $po = $this->poOrFail($user->company_id, $poId);

        try {
            DB::transaction(function () use ($po, $user) {
                PayablesService::approvePurchaseOrder($po, $user);

                Audit::record(
                    entityType: 'purchase_order',
                    entityId: $po->id,
                    action: 'approved',
                    actorUserId: $user->id,
                    details: "{$po->po_number} SGD {$po->total_amount_sgd}",
                    newValue: ['status' => 'approved'],
                );
            });
        } catch (PayablesRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->present($po->fresh()));
    }

    /**
     * "Confirm and import to AP" (2026-09-12): turn an approved PO
     * straight into its matching bill, rather than re-typing the same
     * supplier/description/amount by hand. The new bill is 2-way
     * matched against this same PO (PUR-002), and since the amounts
     * are copied exactly it auto-approves for payment (PUR-003).
     */
    public function importToAp(Request $request, string $poId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $po = $this->poOrFail($user->company_id, $poId);

        try {
            $bill = DB::transaction(function () use ($po, $user) {
                PayablesService::assertPoImportableToAp($po);

                $bill = SupplierInvoice::create([
                    'company_id' => $user->company_id,
                    'supplier_id' => $po->supplier_id,
                    'purchase_order_id' => $po->id,
                    'bill_number' => Numbering::next($user->company_id, 'supplier_invoice'),
                    'invoice_date' => Carbon::today()->toDateString(),
                    'due_date' => PayablesService::dueDateForBill($po->supplier_id, Carbon::today())?->toDateString(),
                    'description' => $po->description,
                    'amount_sgd' => $po->amount_sgd,
                    'gst_amount_sgd' => $po->gst_amount_sgd,
                    'total_amount_sgd' => $po->total_amount_sgd,
                ]);
                PayablesService::matchBillToPo($bill);

                Audit::record(
                    entityType: 'purchase_order',
                    entityId: $po->id,
                    action: 'imported_to_ap',
                    actorUserId: $user->id,
                    details: "{$po->po_number} -> {$bill->bill_number}",
                    newValue: ['bill_number' => $bill->bill_number],
                );

                return $bill;
            });
        } catch (PayablesRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json((new SupplierInvoiceController)->present($bill->fresh()));
    }
}
