<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NEW FEATURE (not a Python->PHP conversion -- see
 * docs/backlog.md / docs/planned-work.md): a contract's own,
 * independent list of CompanyIndividual customers allowed to draw
 * down its pooled hours. Deliberately separate from
 * App\Models\CompanyIndividualRelationship -- a shared-hours customer
 * need not have any relationship record with the contract's own
 * customer at all. See App\Services\ContractService::customerAllowedOnContract().
 */
class ContractSharedCustomer extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['contract_id', 'customer_id', 'added_by_user_id'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CompanyIndividual::class);
    }
}
