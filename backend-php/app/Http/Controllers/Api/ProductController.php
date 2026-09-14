<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Product;
use App\Services\Audit;
use App\Services\Authority;
use Illuminate\Http\Request;

/**
 * Product/Service Catalog. Mirrors backend/app/routers/catalog.py --
 * see App\Models\Product for field rationale. Backs Sales Quotation
 * lines (not yet converted -- see docs/php-conversion-plan.md).
 *
 * NOT yet converted from the Python router: CSV/Excel export.
 */
class ProductController extends Controller
{
    // Gated by "sales", not "company_individual_management" -- matches
    // the Python router's MODULE constant exactly.
    private const MODULE = 'sales';

    // Fields the Python router's update_product actually applies. Note
    // `is_stock` is in ProductUpdate's Pydantic schema but NOT in that
    // loop's field tuple -- i.e. the Python API accepts it in the
    // request body but silently ignores it on update (only settable at
    // create). That looks like an oversight in the Python source
    // rather than a confirmed rule, but this is a straight conversion,
    // not a bug-fix pass -- see docs/php-conversion-plan.md's
    // Conventions section ("faithful conversion, not silent fixes").
    // Flagging it here rather than changing behaviour on our own
    // judgement.
    private const UPDATABLE_FIELDS = [
        'product_type', 'name', 'internal_reference', 'product_category', 'tags',
        'sales_price_sgd', 'cost_sgd', 'unit_of_measure', 'tax_code',
        'default_reference_code_id', 'is_active',
    ];

    private function productOrFail(string $companyId, string $productId): Product
    {
        $product = Product::find($productId);
        if (! $product || $product->company_id !== $companyId) {
            throw new ApiException(404, 'Catalog item not found');
        }

        return $product;
    }

    /** Casts the money fields to float for the JSON boundary -- see App\Models\Product's cast comment. */
    private function present(Product $product): array
    {
        $data = $product->toArray();
        $data['sales_price_sgd'] = (float) $product->sales_price_sgd;
        $data['cost_sgd'] = $product->cost_sgd !== null ? (float) $product->cost_sgd : null;

        return $data;
    }

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $query = Product::where('company_id', $user->company_id);
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return $query->orderBy('name')->get()->map(fn ($p) => $this->present($p))->values();
    }

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $data = $request->validate([
            'product_type' => 'sometimes|in:service,product',
            'name' => 'required|string',
            'internal_reference' => 'sometimes|nullable|string',
            'product_category' => 'sometimes|nullable|string',
            'tags' => 'sometimes|nullable|string',
            'sales_price_sgd' => 'sometimes|numeric|min:0',
            'cost_sgd' => 'sometimes|nullable|numeric|min:0',
            'unit_of_measure' => 'sometimes|nullable|string',
            'tax_code' => 'sometimes|string',
            'default_reference_code_id' => 'sometimes|nullable|uuid',
            'is_stock' => 'sometimes|boolean',
        ]);
        $data['product_type'] ??= Product::TYPE_SERVICE;
        $data['sales_price_sgd'] ??= 0;
        $data['tax_code'] ??= 'SR'; // DEFAULT_TAX_CODE, backend/app/models/tax.py

        $product = Product::create(array_merge($data, ['company_id' => $user->company_id]));

        Audit::record(
            'product', $product->id, 'created', $user->id,
            details: "name={$data['name']}",
            newValue: ['name' => $data['name'], 'sales_price_sgd' => $data['sales_price_sgd']],
        );

        return response()->json($this->present($product->fresh()));
    }

    public function update(Request $request, string $productId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $product = $this->productOrFail($user->company_id, $productId);
        $fields = $request->validate([
            'product_type' => 'sometimes|in:service,product',
            'name' => 'sometimes|string',
            'internal_reference' => 'sometimes|nullable|string',
            'product_category' => 'sometimes|nullable|string',
            'tags' => 'sometimes|nullable|string',
            'sales_price_sgd' => 'sometimes|numeric|min:0',
            'cost_sgd' => 'sometimes|nullable|numeric|min:0',
            'unit_of_measure' => 'sometimes|nullable|string',
            'tax_code' => 'sometimes|string',
            'default_reference_code_id' => 'sometimes|nullable|uuid',
            'is_stock' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        $oldValue = [];
        $newValue = [];
        foreach (self::UPDATABLE_FIELDS as $field) {
            if (! array_key_exists($field, $fields)) {
                continue;
            }
            $old = $product->{$field};
            $new = $fields[$field];
            // Compare as strings for the decimal-cast money fields so
            // "300.00" == "300.0" doesn't register as a change.
            $changed = in_array($field, ['sales_price_sgd', 'cost_sgd'], true)
                ? (float) $old !== (float) $new
                : $old !== $new;
            if (! $changed) {
                continue;
            }
            $oldValue[$field] = $old;
            $newValue[$field] = $new;
            $product->{$field} = $new;
        }

        Audit::record('product', $product->id, 'updated', $user->id, oldValue: $oldValue ?: null, newValue: $newValue ?: null);
        $product->save();

        return response()->json($this->present($product->fresh()));
    }
}
