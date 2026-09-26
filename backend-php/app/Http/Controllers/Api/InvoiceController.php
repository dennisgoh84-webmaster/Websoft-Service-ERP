<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Exceptions\BillingRuleViolation;
use App\Exceptions\CurrencyRuleViolation;
use App\Exceptions\InventoryRuleViolation;
use App\Http\Controllers\Api\Concerns\SendsDocuments;
use App\Http\Controllers\Api\Concerns\SendsExports;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\Warehouse;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\BillingService;
use App\Services\Currency;
use App\Services\DocxForms;
use App\Services\Posting;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sales invoices. Mirrors backend/app/routers/billing.py -- see
 * App\Services\BillingService for the BILL-001/002/005 and SRV-008
 * business logic that issues these (from ContractController::activate()
 * and ExcessUsageService::decideExcessUsage()); this controller only
 * lists/reads what's already been issued.
 *
 * NOT yet converted from the Python router: CSV/Excel export. (The
 * .docx export and "Email Invoice" endpoints WERE the other gap here;
 * both are converted now -- see exportDocx()/email() below and
 * App\Services\DocxForms / App\Services\DocumentEmail.)
 */
class InvoiceController extends Controller
{
    use SendsExports;

    /** @var array<int, string> */
    private const EXPORT_FIELDS = [
        'invoice_number', 'customer_name', 'invoice_type', 'description',
        'amount_sgd', 'gst_amount_sgd', 'total_amount_sgd', 'outstanding_sgd',
        'status', 'issued_at', 'due_date',
    ];

    use SendsDocuments;

    private const MODULE = 'billing';

    private function present(Invoice $invoice): array
    {
        $glEntry = Posting::liveEntryFor(Posting::SOURCE_INVOICE, $invoice->id);

        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'customer_id' => $invoice->customer_id,
            'contract_id' => $invoice->contract_id,
            'invoice_type' => $invoice->invoice_type,
            'description' => $invoice->description,
            'amount_sgd' => (float) $invoice->amount_sgd,
            'tax_code' => $invoice->tax_code,
            'gst_rate' => (float) $invoice->gst_rate,
            'gst_amount_sgd' => (float) $invoice->gst_amount_sgd,
            'total_amount_sgd' => (float) $invoice->total_amount_sgd,
            // Multi-currency: the invoice's own currency, rate and figures in it.
            'currency_code' => $invoice->currencyCode(),
            'exchange_rate' => (float) $invoice->rate(),
            'amount_fx' => $invoice->fx('amount')->toFloat(),
            'gst_amount_fx' => $invoice->fx('gst_amount')->toFloat(),
            'total_amount_fx' => $invoice->fx('total_amount')->toFloat(),
            'outstanding_fx' => $invoice->outstandingFx()->toFloat(),
            'amount_paid_sgd' => (float) $invoice->amount_paid_sgd,
            // Taken off by issued credit notes (BILL-003).
            'credited_sgd' => (float) $invoice->credited_sgd,
            'outstanding_sgd' => $invoice->outstandingSgd()->toFloat(),
            'due_date' => optional($invoice->due_date)->toDateString(),
            'status' => $invoice->status,
            'is_disputed' => $invoice->is_disputed,
            // Brought in by Data Migration as history (docs/data-migration.md).
            'migrated' => $invoice->migrated_at !== null,
            'dispute_note' => $invoice->dispute_note,
            'issued_at' => optional($invoice->issued_at)->toIso8601String(),
            'gl_status' => $glEntry ? 'posted' : 'not_posted',
            'gl_voucher_number' => $glEntry?->voucher_number,
            'cost_sgd' => $invoice->cost_sgd !== null ? (float) $invoice->cost_sgd : null,
            // Empty on every auto-issued invoice -- only a manually
            // raised Sales Invoice carries lines.
            'lines' => $invoice->lines->map(fn (InvoiceLine $l) => [
                'id' => $l->id,
                'line_no' => $l->line_no,
                'description' => $l->description,
                'product_id' => $l->product_id,
                'stock_item_id' => $l->stock_item_id,
                'warehouse_id' => $l->warehouse_id,
                'quantity' => $l->quantity,
                'unit_of_measure' => $l->unit_of_measure,
                'unit_price_sgd' => (float) $l->unit_price_sgd,
                'line_amount_sgd' => (float) $l->line_amount_sgd,
                'unit_price_fx' => (float) ($l->unit_price_fx ?? $l->unit_price_sgd),
                'line_amount_fx' => (float) ($l->line_amount_fx ?? $l->line_amount_sgd),
                'unit_cost_sgd' => $l->unit_cost_sgd !== null ? (float) $l->unit_cost_sgd : null,
                'cost_amount_sgd' => $l->cost_amount_sgd !== null ? (float) $l->cost_amount_sgd : null,
            ])->values(),
        ];
    }

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->filtered($user->company_id, $request)->map(fn (Invoice $i) => $this->present($i))->values();
    }

    /**
     * The list the screen shows, honouring its filters -- shared with
     * the exports so an Export button always returns what is on
     * screen.
     *
     * @return Collection<int, Invoice>
     */
    private function filtered(string $companyId, Request $request)
    {
        $query = Invoice::where('company_id', $companyId);
        foreach (['customer_id', 'contract_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }

        return $query->orderByDesc('issued_at')->get();
    }

    /** @return array<int, array<string, mixed>> */
    private function exportRows(string $companyId, Request $request): array
    {
        $customerNames = CompanyIndividual::where('company_id', $companyId)->pluck('name', 'id');

        return $this->filtered($companyId, $request)->map(fn (Invoice $i) => [
            'invoice_number' => $i->invoice_number,
            'customer_name' => $customerNames[$i->customer_id] ?? '',
            'invoice_type' => $i->invoice_type,
            'description' => $i->description,
            'amount_sgd' => number_format((float) $i->amount_sgd, 2, '.', ''),
            'gst_amount_sgd' => number_format((float) $i->gst_amount_sgd, 2, '.', ''),
            'total_amount_sgd' => number_format((float) $i->total_amount_sgd, 2, '.', ''),
            'outstanding_sgd' => $i->outstandingSgd()->toString(),
            'status' => $i->status,
            // The date only: an exact issue time is more than a
            // listing needs.
            'issued_at' => optional($i->issued_at)->toDateString(),
            'due_date' => optional($i->due_date)->toDateString() ?? '',
        ])->all();
    }

    public function exportCsv(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->csvResponse(
            self::EXPORT_FIELDS, $this->exportRows($user->company_id, $request), 'invoices.csv'
        );
    }

    public function exportExcel(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->xlsxResponse(
            self::EXPORT_FIELDS, $this->exportRows($user->company_id, $request), 'Invoices', 'invoices.xlsx'
        );
    }

    public function show(Request $request, string $invoiceId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $invoice = Invoice::find($invoiceId);
        if (! $invoice || $invoice->company_id !== $user->company_id) {
            throw new ApiException(404, 'Invoice not found');
        }

        return response()->json($this->present($invoice));
    }

    /**
     * Raise a Sales Invoice by hand, with lines that may pick stock
     * (Dennis, 2026-09-15). Everything else in this module issues
     * invoices automatically off another module's decision; this is
     * the only entry point a person drives.
     *
     * EDIT, not FULL: raising an invoice is the same level of act as
     * the contract activation and excess-usage decision that issue one
     * automatically, both of which need EDIT on their own modules.
     *
     * The whole thing is one transaction -- a line asking for more
     * stock than the warehouse holds refuses the entire invoice, and
     * leaves no half-issued stock behind it.
     */
    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $data = $request->validate([
            'customer_id' => 'required|uuid',
            'description' => 'sometimes|nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.description' => 'required|string',
            'lines.*.quantity' => 'required|integer|gt:0',
            // Multi-currency: `unit_price` is in the invoice's currency;
            // `unit_price_sgd` is kept for an SGD invoice.
            'lines.*.unit_price' => 'required_without:lines.*.unit_price_sgd|nullable|numeric|min:0',
            'lines.*.unit_price_sgd' => 'required_without:lines.*.unit_price|nullable|numeric|min:0',
            'currency_code' => 'sometimes|nullable|string|size:3',
            'exchange_rate' => 'sometimes|nullable|numeric|gt:0',
            'lines.*.product_id' => 'sometimes|nullable|uuid',
            'lines.*.stock_item_id' => 'sometimes|nullable|uuid',
            'lines.*.warehouse_id' => 'sometimes|nullable|uuid',
            'lines.*.unit_of_measure' => 'sometimes|nullable|string|max:20',
        ]);

        $this->assertBelongsToCompany($user->company_id, $data);
        try {
            [$currency, $rate] = Currency::resolve($user->company_id, $data['currency_code'] ?? null, $data['exchange_rate'] ?? null, Carbon::today(), $data['customer_id']);
        } catch (CurrencyRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        try {
            $invoice = DB::transaction(fn () => BillingService::issueSalesInvoice(
                companyId: $user->company_id,
                customerId: $data['customer_id'],
                lines: $data['lines'],
                actorUserId: $user->id,
                description: $data['description'] ?? null,
                currency: $currency,
                rate: $rate,
            ));
        } catch (BillingRuleViolation|InventoryRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->present($invoice->fresh()), 201);
    }

    /**
     * Every id in the request must belong to the caller's own company.
     * Without this a valid uuid from another company would be accepted
     * by the foreign key and quietly issue their stock.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertBelongsToCompany(string $companyId, array $data): void
    {
        $customer = CompanyIndividual::find($data['customer_id']);
        if (! $customer || $customer->company_id !== $companyId) {
            throw new ApiException(404, 'Company / Individual not found');
        }

        foreach ($data['lines'] as $i => $line) {
            $checks = [
                'product_id' => [Product::class, 'Product'],
                'stock_item_id' => [StockItem::class, 'Stock item'],
                'warehouse_id' => [Warehouse::class, 'Warehouse'],
            ];
            foreach ($checks as $field => [$model, $label]) {
                $id = $line[$field] ?? null;
                if ($id === null) {
                    continue;
                }
                $row = $model::find($id);
                if (! $row || $row->company_id !== $companyId) {
                    $lineNo = $i + 1;
                    throw new ApiException(404, "{$label} not found (line {$lineNo})");
                }
            }
        }
    }

    private function invoiceOrFail(string $companyId, string $invoiceId): Invoice
    {
        $invoice = Invoice::find($invoiceId);
        if (! $invoice || $invoice->company_id !== $companyId) {
            throw new ApiException(404, 'Invoice not found');
        }

        return $invoice;
    }

    /** GET /invoices/{id}/export.docx -- the Word button on the Invoice print page. */
    public function exportDocx(Request $request, string $invoiceId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $invoice = $this->invoiceOrFail($user->company_id, $invoiceId);
        $customer = CompanyIndividual::find($invoice->customer_id);
        $company = Company::find($user->company_id);

        return $this->docxResponse(
            DocxForms::invoiceToDocx($invoice, $customer, $company),
            "{$invoice->invoice_number}.docx",
        );
    }

    /**
     * Email Sales Invoice (2026-09-12) -- same real-send pattern as
     * Purchase Order's Email button.
     */
    public function email(Request $request, string $invoiceId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $invoice = $this->invoiceOrFail($user->company_id, $invoiceId);
        $customer = CompanyIndividual::find($invoice->customer_id);
        if (! $customer || ! $customer->billing_email) {
            throw new ApiException(422, 'This customer has no email on file -- add one on the Company/Individual page first.');
        }
        $company = Company::find($user->company_id);
        $companyName = $this->companyName($company);
        $docxBytes = DocxForms::invoiceToDocx($invoice, $customer, $company);
        $total = number_format((float) $invoice->total_amount_sgd, 2, '.', '');
        $body = "Dear {$customer->name},\n\n"
            ."Please find attached Invoice {$invoice->invoice_number} for SGD {$total}"
            .($invoice->due_date ? ', due '.$invoice->due_date->toDateString().".\n\n" : ".\n\n")
            ."Regards,\n{$companyName}";

        $result = $this->emailDocument(
            $company,
            $customer->billing_email,
            "Invoice {$invoice->invoice_number} - {$companyName}",
            $body,
            $docxBytes,
            $invoice->invoice_number,
        );

        Audit::record(
            entityType: 'invoice',
            entityId: $invoice->id,
            action: 'emailed',
            actorUserId: $user->id,
            details: "{$invoice->invoice_number} emailed to {$customer->billing_email}",
        );

        return response()->json($result);
    }
}
