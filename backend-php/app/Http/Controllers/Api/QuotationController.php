<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Exceptions\CurrencyRuleViolation;
use App\Exceptions\QuotationRuleViolation;
use App\Http\Controllers\Api\Concerns\SendsDocuments;
use App\Http\Controllers\Api\Concerns\SendsExports;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Prospect;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\Currency;
use App\Services\DocxForms;
use App\Services\Numbering;
use App\Services\QuotationService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sales Quotation API. Mirrors backend/app/routers/quotations.py --
 * see App\Services\QuotationService for the totals + accept -> auto-
 * Contract conversion this controller only orchestrates.
 *
 * NOT yet converted from the Python router (tracked in
 * docs/php-conversion-plan.md): CSV/Excel export. (The `.docx` export
 * and "Email Quotation" endpoints WERE the other gap here; both are
 * converted now -- see exportDocx()/email() below.)
 */
class QuotationController extends Controller
{
    use SendsExports;

    /** @var array<int, string> */
    private const EXPORT_FIELDS = [
        'quotation_number', 'customer_name', 'quotation_date', 'valid_until', 'status',
        'amount_sgd', 'gst_amount_sgd', 'total_amount_sgd',
    ];

    use SendsDocuments;

    private const MODULE = 'sales';

    private function quotationOrFail(string $companyId, string $quotationId): Quotation
    {
        $quotation = Quotation::with('lines')->find($quotationId);
        if (! $quotation || $quotation->company_id !== $companyId) {
            throw new ApiException(404, 'Quotation not found');
        }

        return $quotation;
    }

    private function present(Quotation $quotation): array
    {
        $revision = $quotation->status === Quotation::STATUS_TO_REVISE
            ? $quotation->revisions()->orderByDesc('quotation_number')->first()
            : null;

        return [
            'id' => $quotation->id,
            'quotation_number' => $quotation->quotation_number,
            'customer_id' => $quotation->customer_id,
            // For the Mobile App's quotation cards, which have no customer list to look names up in.
            'customer_name' => $quotation->customer?->name,
            'prospect_id' => $quotation->prospect_id,
            'prospect_number' => $quotation->prospect?->prospect_number,
            'prospect_title' => $quotation->prospect?->title,
            'quotation_date' => optional($quotation->quotation_date)->toDateString(),
            'valid_until' => optional($quotation->valid_until)->toDateString(),
            'status' => $quotation->status,
            'notes' => $quotation->notes,
            'amount_sgd' => (float) $quotation->amount_sgd,
            'tax_code' => $quotation->tax_code,
            'gst_rate' => (float) $quotation->gst_rate,
            'gst_amount_sgd' => (float) $quotation->gst_amount_sgd,
            'total_amount_sgd' => (float) $quotation->total_amount_sgd,
            'currency_code' => $quotation->currencyCode(),
            'exchange_rate' => (float) $quotation->rate(),
            'amount_fx' => $quotation->fx('amount')->toFloat(),
            'gst_amount_fx' => $quotation->fx('gst_amount')->toFloat(),
            'total_amount_fx' => $quotation->fx('total_amount')->toFloat(),
            'converted_contract_id' => $quotation->converted_contract_id,
            'converted_annual_contract_id' => $quotation->converted_annual_contract_id,
            // Decision 11.2: the Sales Invoice issued for its product lines on acceptance.
            'converted_invoice_id' => $quotation->converted_invoice_id,
            'converted_invoice_number' => $quotation->convertedInvoice?->invoice_number,
            'created_at' => optional($quotation->created_at)->toJSON(),
            'submitted_at' => optional($quotation->submitted_at)->toJSON(),
            'approved_at' => optional($quotation->approved_at)->toJSON(),
            'approved_by_user_id' => $quotation->approved_by_user_id,
            'sent_at' => optional($quotation->sent_at)->toJSON(),
            'returned_reason' => $quotation->returned_reason,
            // SALES-006: set when this was raised from a contract as its renewal.
            'renews_contract_id' => $quotation->renews_contract_id,
            'renews_contract_number' => $quotation->renewsContract?->contract_number,
            // To revise (2026-09-15): what the customer asked for, and the
            // revision raised from this / the original this revises.
            'to_revise_at' => optional($quotation->to_revise_at)->toJSON(),
            'revision_reason' => $quotation->revision_reason,
            'revised_from_quotation_id' => $quotation->revised_from_quotation_id,
            'revised_from_quotation_number' => $quotation->revisedFrom?->quotation_number,
            'revision_id' => $revision?->id,
            'revision_number' => $revision?->quotation_number,
            'revision_status' => $revision?->status,
            'lines' => $quotation->lines->map(fn (QuotationLine $l) => [
                'id' => $l->id,
                'product_id' => $l->product_id,
                'description' => $l->description,
                'unit_of_measure' => $l->unit_of_measure,
                'quantity' => (float) $l->quantity,
                'unit_price_sgd' => (float) $l->unit_price_sgd,
                'line_total_sgd' => (float) $l->line_total_sgd,
                'unit_price_fx' => (float) ($l->unit_price_fx ?? $l->unit_price_sgd),
                'line_total_fx' => (float) ($l->line_total_fx ?? $l->line_total_sgd),
                'reference_code_id' => $l->reference_code_id,
                'cost_sgd' => $l->cost_sgd !== null ? (float) $l->cost_sgd : null,
                // What Accept will do with the line (only worked out while
                // it can still be accepted): a product line goes on the
                // Sales Invoice, and a stock line also needs a warehouse.
                'is_product_line' => $quotation->status === Quotation::STATUS_SENT ? QuotationService::isProductLine($l) : null,
                'is_stock_line' => $quotation->status === Quotation::STATUS_SENT ? QuotationService::stockItemFor($l) !== null : null,
            ])->values(),
        ];
    }

    private function filterQuotations(Request $request, string $companyId)
    {
        $query = Quotation::with('lines')->where('company_id', $companyId);

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('prospect_id')) {
            $query->where('prospect_id', $request->query('prospect_id'));
        }

        return $query->orderByDesc('quotation_date')->orderByDesc('quotation_number')->get();
    }

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->filterQuotations($request, $user->company_id)->map(fn ($q) => $this->present($q))->values();
    }

    /** @return array<int, array<string, mixed>> */
    private function exportRows(string $companyId, Request $request): array
    {
        $customerNames = CompanyIndividual::where('company_id', $companyId)->pluck('name', 'id');

        return $this->filterQuotations($request, $companyId)->map(fn (Quotation $q) => [
            'quotation_number' => $q->quotation_number,
            'customer_name' => $customerNames[$q->customer_id] ?? '',
            'quotation_date' => optional($q->quotation_date)->toDateString(),
            'valid_until' => optional($q->valid_until)->toDateString() ?? '',
            'status' => $q->status,
            'amount_sgd' => number_format((float) $q->amount_sgd, 2, '.', ''),
            'gst_amount_sgd' => number_format((float) $q->gst_amount_sgd, 2, '.', ''),
            'total_amount_sgd' => number_format((float) $q->total_amount_sgd, 2, '.', ''),
        ])->all();
    }

    public function exportCsv(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->csvResponse(
            self::EXPORT_FIELDS, $this->exportRows($user->company_id, $request), 'quotations.csv'
        );
    }

    public function exportExcel(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->xlsxResponse(
            self::EXPORT_FIELDS, $this->exportRows($user->company_id, $request), 'Quotations', 'quotations.xlsx'
        );
    }

    public function show(Request $request, string $quotationId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return response()->json($this->present($this->quotationOrFail($user->company_id, $quotationId)));
    }

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $data = $request->validate([
            'customer_id' => 'required|uuid',
            'prospect_id' => 'sometimes|nullable|uuid',
            'quotation_date' => 'required|date',
            'valid_until' => 'sometimes|nullable|date',
            'notes' => 'sometimes|nullable|string',
            'lines' => 'sometimes|array',
            'lines.*.product_id' => 'sometimes|nullable|uuid',
            'lines.*.description' => 'required|string',
            'lines.*.unit_of_measure' => 'sometimes|nullable|string',
            'lines.*.quantity' => 'required|numeric|gt:0',
            // Multi-currency: `unit_price` is in the quotation's currency;
            // `unit_price_sgd` is kept for an SGD quotation.
            'lines.*.unit_price' => 'required_without:lines.*.unit_price_sgd|nullable|numeric|min:0',
            'lines.*.unit_price_sgd' => 'required_without:lines.*.unit_price|nullable|numeric|min:0',
            'currency_code' => 'sometimes|nullable|string|size:3',
            'exchange_rate' => 'sometimes|nullable|numeric|gt:0',
            'lines.*.reference_code_id' => 'sometimes|nullable|uuid',
            'lines.*.cost_sgd' => 'sometimes|nullable|numeric',
        ]);

        $customer = CompanyIndividual::find($data['customer_id']);
        if (! $customer || $customer->company_id !== $user->company_id) {
            throw new ApiException(404, 'Company / Individual not found');
        }

        $lines = $data['lines'] ?? [];
        if (empty($lines)) {
            throw new ApiException(422, 'A quotation needs at least one line.');
        }
        $prospect = empty($data['prospect_id']) ? null : $this->prospectFor($user->company_id, $data['prospect_id'], $data['customer_id']);
        try {
            [$currency, $xrate] = Currency::resolve($user->company_id, $data['currency_code'] ?? null, $data['exchange_rate'] ?? null, $data['quotation_date'], $data['customer_id']);
        } catch (CurrencyRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        $quotation = DB::transaction(function () use ($user, $data, $customer, $lines, $prospect, $currency, $xrate) {
            $quotation = Quotation::create([
                'company_id' => $user->company_id,
                'quotation_number' => Numbering::next($user->company_id, 'quotation'),
                'customer_id' => $data['customer_id'],
                'prospect_id' => $prospect?->id,
                'quotation_date' => $data['quotation_date'],
                'valid_until' => $data['valid_until'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $user->id,
                'currency_code' => $currency,
                'exchange_rate' => $xrate,
            ]);

            foreach ($lines as $line) {
                $qty = Money::of($line['quantity']);
                $priceFx = Money::of($line['unit_price'] ?? $line['unit_price_sgd']);
                $price = Currency::toSgd($priceFx, $xrate);

                // Reference Monitor: an explicit reference_code_id on
                // the line always wins; otherwise fall back to the
                // chosen product's own default
                // (Product::default_reference_code_id), if any.
                $referenceCodeId = $line['reference_code_id'] ?? null;
                $product = ! empty($line['product_id']) ? Product::find($line['product_id']) : null;
                if ($product && $product->company_id !== $user->company_id) {
                    $product = null;
                }
                if ($referenceCodeId === null && $product) {
                    $referenceCodeId = $product->default_reference_code_id;
                }

                // Costing: same pattern -- an explicit cost_sgd on the
                // line always wins (this is the only source of cost
                // for a non-product line); otherwise default from the
                // chosen product's own Product.cost_sgd.
                $costSgd = array_key_exists('cost_sgd', $line) && $line['cost_sgd'] !== null
                    ? Money::of($line['cost_sgd'])->toString()
                    : null;
                if ($costSgd === null && $product && $product->cost_sgd !== null) {
                    $costSgd = Money::of($product->cost_sgd)->toString();
                }

                QuotationLine::create([
                    'quotation_id' => $quotation->id,
                    // Stored as given even when the product lookup
                    // above was discarded for belonging to another
                    // company (only the default-filling use of
                    // $product is skipped) -- matches the Python
                    // router's own behaviour exactly.
                    'product_id' => $line['product_id'] ?? null,
                    'description' => $line['description'],
                    'unit_of_measure' => $line['unit_of_measure'] ?? null,
                    'quantity' => $qty->toString(),
                    'unit_price_sgd' => $price->toString(),
                    'line_total_sgd' => Currency::toSgd($qty->multipliedByMoney($priceFx)->quantize(), $xrate)->toString(),
                    'unit_price_fx' => $priceFx->toString(),
                    'line_total_fx' => $qty->multipliedByMoney($priceFx)->quantize()->toString(),
                    'reference_code_id' => $referenceCodeId,
                    'cost_sgd' => $costSgd,
                ]);
            }

            $quotation->load('lines');
            QuotationService::recomputeTotals($quotation);
            $quotation->save();

            Audit::record(
                'quotation', $quotation->id, 'created', $user->id,
                details: "{$quotation->quotation_number} for {$customer->name}",
                newValue: ['customer' => $customer->name, 'total_amount_sgd' => (float) $quotation->total_amount_sgd],
            );

            return $quotation;
        });

        return response()->json($this->present($quotation->fresh('lines')));
    }

    /** draft -> pending_approval. */
    public function submit(Request $request, string $quotationId)
    {
        return $this->transition($request, $quotationId, 'edit',
            fn (Quotation $q, User $u) => QuotationService::submitForApproval($q, $u->id));
    }

    /** pending_approval -> approved (Sales Manager / owner, BILL-006). */
    public function approve(Request $request, string $quotationId)
    {
        return $this->transition($request, $quotationId, 'edit',
            fn (Quotation $q, User $u) => QuotationService::approve($q, $u));
    }

    /** pending_approval -> draft, with a reason. */
    public function sendBack(Request $request, string $quotationId)
    {
        $data = $request->validate(['reason' => 'required|string|min:1|max:1000']);

        return $this->transition($request, $quotationId, 'edit',
            fn (Quotation $q, User $u) => QuotationService::sendBack($q, $u, $data['reason']));
    }

    /** approved -> sent. */
    public function send(Request $request, string $quotationId)
    {
        return $this->transition($request, $quotationId, 'edit',
            fn (Quotation $q, User $u) => QuotationService::send($q, $u->id));
    }

    /** sent -> to_revise, with what the customer asked to change. */
    public function toRevise(Request $request, string $quotationId)
    {
        $data = $request->validate(['reason' => 'required|string|min:1|max:2000']);

        return $this->transition($request, $quotationId, 'edit',
            fn (Quotation $q, User $u) => QuotationService::markToRevise($q, $u->id, $data['reason']));
    }

    /** to_revise -> a new draft revision, returned. */
    public function revise(Request $request, string $quotationId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $original = $this->quotationOrFail($user->company_id, $quotationId);
        try {
            $revision = QuotationService::createRevision($original, $user->id);
        } catch (QuotationRuleViolation $e) {
            throw new ApiException(409, $e->getMessage());
        }

        return response()->json($this->present($revision->fresh('lines')));
    }

    /**
     * Put a quotation under a prospect, move it to another, or take it
     * off (prospect_id null) -- for quotations raised before the
     * prospect existed. Invoices already issued from the contracts it
     * became move with it, so the prospect's billed and paid amounts
     * stay whole.
     */
    public function linkProspect(Request $request, string $quotationId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');
        $quotation = $this->quotationOrFail($user->company_id, $quotationId);
        $data = $request->validate(['prospect_id' => 'present|nullable|uuid']);
        $prospect = $data['prospect_id'] === null ? null : $this->prospectFor($user->company_id, $data['prospect_id'], $quotation->customer_id);

        DB::transaction(function () use ($quotation, $prospect, $user) {
            $old = $quotation->prospect_id;
            $quotation->prospect_id = $prospect?->id;
            $quotation->save();
            $contractIds = Contract::where('quotation_id', $quotation->id)->pluck('id');
            Invoice::whereIn('contract_id', $contractIds)->update(['prospect_id' => $prospect?->id]);
            Audit::record('quotation', $quotation->id, 'prospect_linked', $user->id,
                details: "{$quotation->quotation_number} -> ".($prospect?->prospect_number ?? '(no prospect)'),
                oldValue: ['prospect_id' => $old], newValue: ['prospect_id' => $prospect?->id]);
        });

        return response()->json($this->present($quotation->fresh(['lines'])));
    }

    /** A prospect of this company, for the same Company / Individual as the quotation. */
    private function prospectFor(string $companyId, string $prospectId, string $customerId): Prospect
    {
        $prospect = Prospect::find($prospectId);
        if (! $prospect || $prospect->company_id !== $companyId) {
            throw new ApiException(404, 'Prospect not found');
        }
        if ($prospect->customer_id !== $customerId) {
            throw new ApiException(422, 'That prospect belongs to a different Company / Individual.');
        }

        return $prospect;
    }

    public function reject(Request $request, string $quotationId)
    {
        return $this->transition($request, $quotationId, 'edit',
            fn (Quotation $q, User $u) => QuotationService::reject($q, $u->id));
    }

    /**
     * The shared shape of every status endpoint: authority, ownership,
     * the transition inside a transaction, a 409 when the status model
     * refuses, the fresh record back.
     */
    private function transition(Request $request, string $quotationId, string $level, callable $apply)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, $level);

        $quotation = $this->quotationOrFail($user->company_id, $quotationId);
        try {
            DB::transaction(function () use ($quotation, $user, $apply) {
                $apply($quotation, $user);
                $quotation->save();
            });
        } catch (QuotationRuleViolation $e) {
            throw new ApiException(409, $e->getMessage());
        }

        return response()->json($this->present($quotation->fresh('lines')));
    }

    public function accept(Request $request, string $quotationId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $quotation = $this->quotationOrFail($user->company_id, $quotationId);
        // The warehouse stock lines leave from, when the quotation has any (decision 11.2).
        $warehouseId = $request->validate(['warehouse_id' => 'sometimes|nullable|uuid'])['warehouse_id'] ?? null;
        if ($warehouseId !== null && ! Warehouse::where('company_id', $user->company_id)->whereKey($warehouseId)->exists()) {
            throw new ApiException(422, 'That warehouse is not one of this company\'s.');
        }

        try {
            $message = DB::transaction(function () use ($quotation, $user, $warehouseId) {
                $msg = QuotationService::acceptQuotation($quotation, $user->id, $warehouseId);
                $quotation->save();

                return $msg;
            });
        } catch (QuotationRuleViolation $e) {
            throw new ApiException(409, $e->getMessage());
        }

        return response()->json([
            'quotation' => $this->present($quotation->fresh('lines')),
            'message' => $message,
        ]);
    }

    /** GET /quotations/{id}/export.docx -- the Word button on the Quotation print page. */
    public function exportDocx(Request $request, string $quotationId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $quotation = $this->quotationOrFail($user->company_id, $quotationId);
        $customer = CompanyIndividual::find($quotation->customer_id);
        $company = Company::find($user->company_id);

        return $this->docxResponse(
            DocxForms::quotationToDocx($quotation, $customer, $company),
            "{$quotation->quotation_number}.docx",
        );
    }

    /**
     * Email Sales Quotation (2026-09-12) -- same real-send pattern as
     * Purchase Order's Email button.
     */
    public function email(Request $request, string $quotationId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $quotation = $this->quotationOrFail($user->company_id, $quotationId);
        $customer = CompanyIndividual::find($quotation->customer_id);
        if (! $customer || ! $customer->billing_email) {
            throw new ApiException(422, 'This customer has no email on file -- add one on the Company/Individual page first.');
        }
        $company = Company::find($user->company_id);
        $companyName = $this->companyName($company);
        $docxBytes = DocxForms::quotationToDocx($quotation, $customer, $company);
        $total = number_format((float) $quotation->total_amount_sgd, 2, '.', '');
        $body = "Dear {$customer->name},\n\n"
            ."Please find attached Quotation {$quotation->quotation_number} dated "
            .$quotation->quotation_date->toDateString()." for SGD {$total}.\n\n"
            ."Regards,\n{$companyName}";

        $result = $this->emailDocument(
            $company,
            $customer->billing_email,
            "Quotation {$quotation->quotation_number} - {$companyName}",
            $body,
            $docxBytes,
            $quotation->quotation_number,
        );

        Audit::record(
            entityType: 'quotation',
            entityId: $quotation->id,
            action: 'emailed',
            actorUserId: $user->id,
            details: "{$quotation->quotation_number} emailed to {$customer->billing_email}",
        );

        return response()->json($result);
    }
}
