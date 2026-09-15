<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Concerns\SendsDocuments;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Services\Audit;
use App\Services\Authority;
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
        return [
            'id' => $quotation->id,
            'quotation_number' => $quotation->quotation_number,
            'customer_id' => $quotation->customer_id,
            'quotation_date' => optional($quotation->quotation_date)->toDateString(),
            'valid_until' => optional($quotation->valid_until)->toDateString(),
            'status' => $quotation->status,
            'notes' => $quotation->notes,
            'amount_sgd' => (float) $quotation->amount_sgd,
            'tax_code' => $quotation->tax_code,
            'gst_rate' => (float) $quotation->gst_rate,
            'gst_amount_sgd' => (float) $quotation->gst_amount_sgd,
            'total_amount_sgd' => (float) $quotation->total_amount_sgd,
            'converted_contract_id' => $quotation->converted_contract_id,
            'converted_annual_contract_id' => $quotation->converted_annual_contract_id,
            'created_at' => optional($quotation->created_at)->toJSON(),
            'lines' => $quotation->lines->map(fn (QuotationLine $l) => [
                'id' => $l->id,
                'product_id' => $l->product_id,
                'description' => $l->description,
                'unit_of_measure' => $l->unit_of_measure,
                'quantity' => (float) $l->quantity,
                'unit_price_sgd' => (float) $l->unit_price_sgd,
                'line_total_sgd' => (float) $l->line_total_sgd,
                'reference_code_id' => $l->reference_code_id,
                'cost_sgd' => $l->cost_sgd !== null ? (float) $l->cost_sgd : null,
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

        return $query->orderByDesc('quotation_date')->orderByDesc('quotation_number')->get();
    }

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->filterQuotations($request, $user->company_id)->map(fn ($q) => $this->present($q))->values();
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
            'quotation_date' => 'required|date',
            'valid_until' => 'sometimes|nullable|date',
            'notes' => 'sometimes|nullable|string',
            'lines' => 'sometimes|array',
            'lines.*.product_id' => 'sometimes|nullable|uuid',
            'lines.*.description' => 'required|string',
            'lines.*.unit_of_measure' => 'sometimes|nullable|string',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.unit_price_sgd' => 'required|numeric|min:0',
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

        $quotation = DB::transaction(function () use ($user, $data, $customer, $lines) {
            $quotation = Quotation::create([
                'company_id' => $user->company_id,
                'quotation_number' => Numbering::next($user->company_id, 'quotation'),
                'customer_id' => $data['customer_id'],
                'quotation_date' => $data['quotation_date'],
                'valid_until' => $data['valid_until'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $user->id,
            ]);

            foreach ($lines as $line) {
                $qty = Money::of($line['quantity']);
                $price = Money::of($line['unit_price_sgd']);

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
                    'line_total_sgd' => $qty->multipliedByMoney($price)->quantize()->toString(),
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

    public function send(Request $request, string $quotationId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $quotation = $this->quotationOrFail($user->company_id, $quotationId);
        if ($quotation->status !== Quotation::STATUS_DRAFT) {
            throw new ApiException(409, 'Only a draft quotation can be sent.');
        }

        $quotation->status = Quotation::STATUS_SENT;
        Audit::record('quotation', $quotation->id, 'sent', $user->id);
        $quotation->save();

        return response()->json($this->present($quotation->fresh('lines')));
    }

    public function accept(Request $request, string $quotationId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $quotation = $this->quotationOrFail($user->company_id, $quotationId);
        if (! in_array($quotation->status, [Quotation::STATUS_DRAFT, Quotation::STATUS_SENT], true)) {
            throw new ApiException(409, "Cannot accept a {$quotation->status} quotation.");
        }

        $message = DB::transaction(function () use ($quotation, $user) {
            $msg = QuotationService::acceptQuotation($quotation, $user->id);
            $quotation->save();

            return $msg;
        });

        return response()->json([
            'quotation' => $this->present($quotation->fresh('lines')),
            'message' => $message,
        ]);
    }

    public function reject(Request $request, string $quotationId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $quotation = $this->quotationOrFail($user->company_id, $quotationId);
        if (! in_array($quotation->status, [Quotation::STATUS_DRAFT, Quotation::STATUS_SENT], true)) {
            throw new ApiException(409, "Cannot reject a {$quotation->status} quotation.");
        }

        $quotation->status = Quotation::STATUS_REJECTED;
        Audit::record('quotation', $quotation->id, 'rejected', $user->id);
        $quotation->save();

        return response()->json($this->present($quotation->fresh('lines')));
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
