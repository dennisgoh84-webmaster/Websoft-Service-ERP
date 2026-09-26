<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Exceptions\CurrencyRuleViolation;
use App\Exceptions\PayablesRuleViolation;
use App\Http\Controllers\Api\Concerns\SendsDocuments;
use App\Http\Controllers\Api\Concerns\SendsExports;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Models\TaxCode;
use App\Services\ApprovalService;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\Currency;
use App\Services\DocxForms;
use App\Services\Numbering;
use App\Services\PayablesService;
use App\Services\Tax;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Purchase Orders. Mirrors the purchase-order half of
 * backend/app/routers/payables.py -- see App\Services\PayablesService
 * for the PUR-001/002/003 business logic this only orchestrates.
 *
 * NOT yet converted: CSV/Excel export. (The .docx export and "Email
 * Purchase Order" endpoints WERE the other gap here; both are
 * converted now -- see exportDocx()/email() below.)
 */
class PurchaseOrderController extends Controller
{
    use SendsExports;

    /** @var array<int, string> */
    private const EXPORT_FIELDS = [
        'po_number', 'supplier_name', 'order_date', 'description', 'amount_sgd',
        'gst_amount_sgd', 'total_amount_sgd', 'status',
    ];

    use SendsDocuments;

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
            'currency_code' => $po->currencyCode(),
            'exchange_rate' => (float) $po->rate(),
            'amount_fx' => $po->fx('amount')->toFloat(),
            'gst_amount_fx' => $po->fx('gst_amount')->toFloat(),
            'total_amount_fx' => $po->fx('total_amount')->toFloat(),
            'status' => $po->status,
            // eApproval (Backlog 2): above the supplier's limit, where the approvers' decision stands.
            'approval_status' => ApprovalService::stateOf($po->company_id, 'purchase_order', $po->id),
            'approval_note' => ApprovalService::describeState($po->company_id, 'purchase_order', $po->id),
            'cancel_reason' => $po->cancel_reason,
            'imported_bill_id' => $importedBill?->id,
            'imported_bill_number' => $importedBill?->bill_number,
        ];
    }

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->filtered($user->company_id, $request)
            ->map(fn (PurchaseOrder $po) => $this->present($po))->values();
    }

    /**
     * The list the screen shows, honouring its filters -- shared with
     * the exports so an Export button always returns what is on
     * screen.
     *
     * @return Collection<int, PurchaseOrder>
     */
    private function filtered(string $companyId, Request $request)
    {
        $query = PurchaseOrder::where('company_id', $companyId);
        foreach (['supplier_id', 'status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }

        return $query->orderByDesc('order_date')->get();
    }

    /** @return array<int, array<string, mixed>> */
    private function exportRows(string $companyId, Request $request): array
    {
        $supplierNames = CompanyIndividual::where('company_id', $companyId)->pluck('name', 'id');

        return $this->filtered($companyId, $request)->map(fn (PurchaseOrder $po) => [
            'po_number' => $po->po_number,
            'supplier_name' => $supplierNames[$po->supplier_id] ?? '',
            'order_date' => optional($po->order_date)->toDateString(),
            'description' => $po->description,
            'amount_sgd' => number_format((float) $po->amount_sgd, 2, '.', ''),
            'gst_amount_sgd' => number_format((float) $po->gst_amount_sgd, 2, '.', ''),
            'total_amount_sgd' => number_format((float) $po->total_amount_sgd, 2, '.', ''),
            'status' => $po->status,
        ])->all();
    }

    public function exportCsv(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->csvResponse(
            self::EXPORT_FIELDS, $this->exportRows($user->company_id, $request), 'purchase-orders.csv'
        );
    }

    public function exportExcel(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->xlsxResponse(
            self::EXPORT_FIELDS, $this->exportRows($user->company_id, $request),
            'Purchase Orders', 'purchase-orders.xlsx'
        );
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
            // Multi-currency: `amount` is in the PO's currency; `amount_sgd` is kept for an SGD one.
            'amount' => 'required_without:amount_sgd|nullable|numeric|gt:0',
            'amount_sgd' => 'required_without:amount|nullable|numeric|gt:0',
            'currency_code' => 'sometimes|nullable|string|size:3',
            'exchange_rate' => 'sometimes|nullable|numeric|gt:0',
        ]);
        $this->supplierOrFail($user->company_id, $data['supplier_id']);
        try {
            [$currency, $xrate] = Currency::resolve($user->company_id, $data['currency_code'] ?? null, $data['exchange_rate'] ?? null, $data['order_date'], $data['supplier_id']);
        } catch (CurrencyRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }
        if (! Currency::isBase($currency) && empty($data['amount'])) {
            throw new ApiException(422, "Key the amount in {$currency}.");
        }

        $po = DB::transaction(function () use ($user, $data, $currency, $xrate) {
            // Worked out in the PO's currency, then each figure in SGD at its rate.
            $netFx = Money::of($data['amount'] ?? $data['amount_sgd']);
            [$_taxCode, $_rate, $gstFx, $totalFx] = Tax::applyGst($user->company_id, $netFx);
            $data['amount_sgd'] = Currency::toSgd($netFx, $xrate)->toString();
            $gst = Currency::toSgd($gstFx, $xrate);
            $total = Money::of($data['amount_sgd'])->plus($gst);
            // The supplier's limit is in SGD.
            $needsOwner = PayablesService::poNeedsOwnerApproval($data['supplier_id'], $total);

            $po = PurchaseOrder::create([
                'company_id' => $user->company_id,
                'supplier_id' => $data['supplier_id'],
                'po_number' => Numbering::next($user->company_id, 'purchase_order'),
                'order_date' => $data['order_date'],
                'description' => $data['description'],
                'amount_sgd' => $data['amount_sgd'],
                'gst_amount_sgd' => $gst->toString(),
                'total_amount_sgd' => $total->toString(),
                'currency_code' => $currency,
                'exchange_rate' => $xrate,
                'amount_fx' => $netFx->toString(),
                'gst_amount_fx' => $gstFx->toString(),
                'total_amount_fx' => $totalFx->toString(),
                'status' => $needsOwner ? PurchaseOrder::STATUS_PENDING_APPROVAL : PurchaseOrder::STATUS_DRAFT,
            ]);

            // Above the supplier's PO limit (Backlog 2, 2026-09-26): the
            // eApproval approvers decide, when an authority is set up for
            // Purchase Orders; with none, the owner approves as before.
            if ($needsOwner) {
                $supplierName = CompanyIndividual::whereKey($data['supplier_id'])->value('name');
                ApprovalService::submitForApproval(
                    $user->company_id, 'purchase_order', $po->id, $user->id, $total->toString(),
                    summary: "Purchase Order {$po->po_number}, SGD {$total->toString()} to {$supplierName}",
                );
            }

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
        if (ApprovalService::stateOf($po->company_id, 'purchase_order', $po->id) !== null) {
            throw new ApiException(422, 'This purchase order is above the supplier\'s limit and goes through eApproval -- '.
                ApprovalService::describeState($po->company_id, 'purchase_order', $po->id).' The approvers decide in the Approval Center.');
        }

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
                // Multi-currency: the bill is in the PO's currency, at the
                // Currency Rate Table's rate on the bill date (else the PO's own).
                $code = $po->currencyCode();
                $billRate = Currency::rateOn($user->company_id, $code, Carbon::today()) ?? $po->rate();
                $gstFx = $po->fx('gst_amount');
                $netSgd = Currency::toSgd($po->fx('amount'), $billRate);
                $gstSgd = Currency::toSgd($gstFx, $billRate);

                $bill = SupplierInvoice::create([
                    'company_id' => $user->company_id,
                    'supplier_id' => $po->supplier_id,
                    'purchase_order_id' => $po->id,
                    'bill_number' => Numbering::next($user->company_id, 'supplier_invoice'),
                    'invoice_date' => Carbon::today()->toDateString(),
                    'due_date' => PayablesService::dueDateForBill($po->supplier_id, Carbon::today())?->toDateString(),
                    'description' => $po->description,
                    'amount_sgd' => $netSgd->toString(),
                    // A PO is raised at the standard rate, so its bill is a
                    // standard-rated purchase at the rate the PO charged.
                    'tax_code' => $gstFx->toFloat() > 0 ? TaxCode::DEFAULT_PURCHASE_CODE : null,
                    'gst_rate' => $po->fx('amount')->toFloat() > 0
                        ? round($gstFx->toFloat() * 100 / $po->fx('amount')->toFloat(), 2) : null,
                    'gst_amount_sgd' => $gstSgd->toString(),
                    'total_amount_sgd' => $netSgd->plus($gstSgd)->toString(),
                    'currency_code' => $code,
                    'exchange_rate' => $billRate,
                    'amount_fx' => $po->fx('amount')->toString(),
                    'gst_amount_fx' => $gstFx->toString(),
                    'total_amount_fx' => $po->fx('total_amount')->toString(),
                    'amount_paid_fx' => '0.00',
                ]);
                PayablesService::matchBillToPo($bill, $user->id);

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

    /** GET /accounts-payable/purchase-orders/{id}/export.docx -- the Word button on the PO print page. */
    public function exportDocx(Request $request, string $poId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $po = $this->poOrFail($user->company_id, $poId);
        $supplier = $this->supplierOrFail($user->company_id, $po->supplier_id);
        $company = Company::find($user->company_id);

        return $this->docxResponse(
            DocxForms::purchaseOrderToDocx($po, $supplier, $company),
            "{$po->po_number}.docx",
        );
    }

    /**
     * Real server-side send (2026-09-12), PO PDF attached. The PDF is
     * the same .docx form (see DocxForms::purchaseOrderToDocx)
     * converted via LibreOffice headless -- see App\Services\PdfConvert.
     */
    public function email(Request $request, string $poId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $po = $this->poOrFail($user->company_id, $poId);
        $supplier = $this->supplierOrFail($user->company_id, $po->supplier_id);
        if (! $supplier->billing_email) {
            throw new ApiException(422, "{$supplier->name} has no email on file -- add one on the Company/Individual page first.");
        }
        $company = Company::find($user->company_id);
        $companyName = $this->companyName($company);
        $docxBytes = DocxForms::purchaseOrderToDocx($po, $supplier, $company);
        $total = number_format((float) $po->total_amount_sgd, 2, '.', '');
        $body = "Dear {$supplier->name},\n\n"
            ."Please find attached Purchase Order {$po->po_number} dated ".$po->order_date->toDateString()
            ." for SGD {$total}.\n\n"
            ."Please confirm receipt and quote the PO number on your invoice.\n\n"
            ."Regards,\n{$companyName}";

        $result = $this->emailDocument(
            $company,
            $supplier->billing_email,
            "Purchase Order {$po->po_number} - {$companyName}",
            $body,
            $docxBytes,
            $po->po_number,
        );

        Audit::record(
            entityType: 'purchase_order',
            entityId: $po->id,
            action: 'emailed',
            actorUserId: $user->id,
            details: "{$po->po_number} emailed to {$supplier->billing_email}",
        );

        return response()->json($result);
    }
}
