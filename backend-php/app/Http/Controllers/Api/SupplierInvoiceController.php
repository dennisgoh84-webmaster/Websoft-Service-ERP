<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Exceptions\PayablesRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\CompanyIndividual;
use App\Models\SupplierInvoice;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\Numbering;
use App\Services\PayablesService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Supplier invoices (bills). Mirrors the bill half of
 * backend/app/routers/payables.py -- see App\Services\PayablesService
 * for the PUR-002/003 business logic this only orchestrates.
 *
 * NOT yet converted: CSV/Excel export. See PayablesService's class
 * docblock for the GL-posting known gap on auto-approval.
 */
class SupplierInvoiceController extends Controller
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

    private function billOrFail(string $companyId, string $billId): SupplierInvoice
    {
        $bill = SupplierInvoice::find($billId);
        if (! $bill || $bill->company_id !== $companyId) {
            throw new ApiException(404, 'Supplier invoice not found');
        }

        return $bill;
    }

    public function present(SupplierInvoice $bill): array
    {
        return [
            'id' => $bill->id,
            'bill_number' => $bill->bill_number,
            'supplier_invoice_no' => $bill->supplier_invoice_no,
            'supplier_id' => $bill->supplier_id,
            'purchase_order_id' => $bill->purchase_order_id,
            'invoice_date' => optional($bill->invoice_date)->toDateString(),
            'due_date' => optional($bill->due_date)->toDateString(),
            'description' => $bill->description,
            'amount_sgd' => (float) $bill->amount_sgd,
            'gst_amount_sgd' => (float) $bill->gst_amount_sgd,
            'total_amount_sgd' => (float) $bill->total_amount_sgd,
            'amount_paid_sgd' => (float) $bill->amount_paid_sgd,
            'outstanding_sgd' => $bill->outstandingSgd()->toFloat(),
            'match_status' => $bill->match_status,
            'match_note' => $bill->match_note,
            'status' => $bill->status,
            'gl_status' => 'not_posted',
            'gl_voucher_number' => null,
        ];
    }

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $query = SupplierInvoice::where('company_id', $user->company_id);
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->query('supplier_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return $query->orderByDesc('invoice_date')->get()->map(fn (SupplierInvoice $b) => $this->present($b))->values();
    }

    public function show(Request $request, string $billId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return response()->json($this->present($this->billOrFail($user->company_id, $billId)));
    }

    /**
     * Enter a supplier invoice. It is 2-way matched against its PO
     * immediately (PUR-002); a match auto-approves it for payment
     * (PUR-003), a mismatch becomes an exception.
     */
    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $data = $request->validate([
            'supplier_id' => 'required|uuid',
            'purchase_order_id' => 'sometimes|nullable|uuid',
            'supplier_invoice_no' => 'sometimes|nullable|string',
            'invoice_date' => 'required|date',
            'description' => 'required|string|min:1',
            'amount_sgd' => 'required|numeric|gt:0',
            'gst_amount_sgd' => 'sometimes|numeric|min:0',
        ]);
        $this->supplierOrFail($user->company_id, $data['supplier_id']);

        try {
            $bill = DB::transaction(function () use ($user, $data) {
                $net = (float) $data['amount_sgd'];
                $gst = (float) ($data['gst_amount_sgd'] ?? 0);

                $bill = SupplierInvoice::create([
                    'company_id' => $user->company_id,
                    'supplier_id' => $data['supplier_id'],
                    'purchase_order_id' => $data['purchase_order_id'] ?? null,
                    'bill_number' => Numbering::next($user->company_id, 'supplier_invoice'),
                    'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
                    'invoice_date' => $data['invoice_date'],
                    'due_date' => PayablesService::dueDateForBill($data['supplier_id'], Carbon::parse($data['invoice_date']))?->toDateString(),
                    'description' => $data['description'],
                    'amount_sgd' => $net,
                    'gst_amount_sgd' => $gst,
                    'total_amount_sgd' => $net + $gst,
                ]);

                PayablesService::matchBillToPo($bill);

                Audit::record(
                    entityType: 'supplier_invoice',
                    entityId: $bill->id,
                    action: 'received',
                    actorUserId: $user->id,
                    details: "{$bill->bill_number}: {$bill->match_note}",
                    newValue: [
                        'bill_number' => $bill->bill_number,
                        'total_sgd' => (string) $bill->total_amount_sgd,
                        'match_status' => $bill->match_status,
                        'status' => $bill->status,
                    ],
                );

                return $bill;
            });
        } catch (PayablesRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->present($bill->fresh()));
    }
}
