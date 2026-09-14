<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Product coverage on a contract, and optional per-seat license
 * tracking. Mirrors backend/app/models/contracts.py's ContractProduct.
 */
class ContractProduct extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    public const LICENSE_LOCAL = 'local';

    public const LICENSE_RDP = 'rdp';

    public const LICENSE_WEB = 'web';

    protected $fillable = ['contract_id', 'product_id', 'license_type', 'number_of_licenses'];

    protected $casts = ['number_of_licenses' => 'integer'];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
