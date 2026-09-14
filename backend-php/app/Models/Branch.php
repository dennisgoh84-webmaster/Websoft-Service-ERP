<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A branch location of a CompanyIndividual -- same legal entity/
 * account, different address. Mirrors
 * backend/app/models/company_individuals.py's Branch.
 */
class Branch extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'branches';

    public $timestamps = false;

    protected $fillable = [
        'customer_id', 'branch_code', 'branch_name', 'address_line1',
        'address_line2', 'address_city', 'address_state', 'address_postal_code',
        'address_country', 'phone', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CompanyIndividual::class, 'customer_id');
    }
}
