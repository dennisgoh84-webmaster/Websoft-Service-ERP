<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One invoice line (or part of it) a credit note credits, and whether the goods came back (2026-09-26). */
class CreditNoteLine extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'credit_note_id', 'invoice_line_id', 'line_no', 'description', 'quantity',
        'amount_fx', 'amount_sgd', 'return_to_stock', 'stock_item_id', 'warehouse_id', 'unit_cost_sgd',
    ];

    protected $casts = ['quantity' => 'integer', 'return_to_stock' => 'boolean'];

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }
}
