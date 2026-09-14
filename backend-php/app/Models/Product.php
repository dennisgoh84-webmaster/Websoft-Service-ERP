<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Product/Service Catalog. Mirrors backend/app/models/catalog.py's
 * Product exactly -- see that file's docstring for which Odoo-screen
 * fields were deliberately left out of this first build.
 *
 * `default_reference_code_id` has no FK constraint yet (see the
 * migration) -- Reference Codes isn't converted yet.
 */
class Product extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    public const TYPE_SERVICE = 'service';

    public const TYPE_PRODUCT = 'product';

    protected $fillable = [
        'company_id', 'product_type', 'name', 'internal_reference', 'product_category',
        'tags', 'sales_price_sgd', 'cost_sgd', 'unit_of_measure', 'tax_code',
        'default_reference_code_id', 'is_stock', 'is_active',
    ];

    // Money fields: 'decimal:2' for DB-precision-safe storage/
    // comparison (a future Quotations module will compute line totals
    // from sales_price_sgd via App\Support\Money) -- see
    // docs/php-conversion-plan.md's Decimal/money handling convention.
    // Cast to float only when building an API response (see
    // ProductController::present()), matching Python's ProductOut
    // schema (`sales_price_sgd: float`).
    protected $casts = [
        'sales_price_sgd' => 'decimal:2',
        'cost_sgd' => 'decimal:2',
        'is_stock' => 'boolean',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
