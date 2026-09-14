<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * NEW FEATURE (not a Python->PHP conversion -- see
 * docs/backlog.md / docs/planned-work.md): the Job Implementation
 * Template attached to a Product -- an ordered task checklist. One
 * template per product (see the migration's unique constraint).
 * Selecting this product on a Job Order copies its tasks onto the Job
 * Order as JobOrderImplementationTask rows -- see
 * App\Services\JobOrderImplementationTaskService.
 */
class ProductImplementationTemplate extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['company_id', 'product_id'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProductImplementationTemplateTask::class, 'template_id')->orderBy('sort_order');
    }
}
