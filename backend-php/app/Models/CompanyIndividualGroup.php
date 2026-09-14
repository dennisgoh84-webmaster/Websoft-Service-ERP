<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A lightweight tag linking separate CompanyIndividual records that
 * belong to the same group of companies. Mirrors
 * backend/app/models/company_individuals.py's CompanyIndividualGroup.
 */
class CompanyIndividualGroup extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'company_individual_groups';

    public $timestamps = false;

    protected $fillable = ['company_id', 'name', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'created_at' => 'datetime'];

    public function customers(): HasMany
    {
        return $this->hasMany(CompanyIndividual::class, 'customer_group_id');
    }
}
