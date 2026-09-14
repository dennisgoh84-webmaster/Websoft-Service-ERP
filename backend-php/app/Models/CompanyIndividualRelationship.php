<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A link from one CompanyIndividual to another CompanyIndividual or
 * Contact. Mirrors
 * backend/app/models/company_individuals.py's CompanyIndividualRelationship.
 * Not yet exposed by a controller -- see docs/php-conversion-plan.md.
 */
class CompanyIndividualRelationship extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'company_individual_relationships';

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'from_customer_id', 'to_customer_id', 'to_contact_id',
        'relationship_type', 'note', 'is_active', 'created_by_user_id',
    ];

    protected $casts = ['is_active' => 'boolean', 'created_at' => 'datetime'];

    public function fromCustomer(): BelongsTo
    {
        return $this->belongsTo(CompanyIndividual::class, 'from_customer_id');
    }

    public function toCustomer(): BelongsTo
    {
        return $this->belongsTo(CompanyIndividual::class, 'to_customer_id');
    }

    public function toContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'to_contact_id');
    }
}
