<?php

namespace App\Models;

use App\Models\Concerns\HasLineNumber;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a Sales Quotation. Mirrors
 * backend/app/models/quotations.py's QuotationLine exactly.
 *
 * `reference_code_id` has no FK constraint yet -- see the migration's
 * docblock (Reference Codes isn't converted).
 */
class QuotationLine extends Model
{
    use HasLineNumber, HasUuidPrimaryKey;

    public const LINE_PARENT_KEY = 'quotation_id';

    public $timestamps = false;

    protected $fillable = [
        'quotation_id', 'product_id', 'description', 'unit_of_measure', 'quantity',
        'unit_price_sgd', 'line_total_sgd', 'reference_code_id', 'cost_sgd',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price_sgd' => 'decimal:2',
        'line_total_sgd' => 'decimal:2',
        'cost_sgd' => 'decimal:2',
    ];

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
