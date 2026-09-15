<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reference Monitor -- sub-codes of one Chart of Accounts row. Mirrors
 * backend/app/models/reference_codes.py's ReferenceCode.
 *
 * A single GL code (e.g. 45001 "Sales of Software Revenue") is broken
 * down into several named sub-codes for document selection, all posting
 * to the same account. A Product can carry a default one
 * (Product.default_reference_code_id) and a Quotation Line captures or
 * overrides it (QuotationLine.reference_code_id) -- the only document
 * type with real per-line item selection today.
 *
 * No document type auto-posts to the General Ledger through a reference
 * code yet, so the "eventually post to Chart of Accounts transactions"
 * half of the original request stays future scope, exactly as in
 * Python.
 */
class ReferenceCode extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['company_id', 'account_id', 'code', 'name', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
