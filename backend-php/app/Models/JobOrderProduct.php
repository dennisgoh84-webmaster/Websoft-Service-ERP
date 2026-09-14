<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NEW FEATURE (not a Python->PHP conversion -- see
 * docs/backlog.md / docs/planned-work.md): pivot for a Job Order's
 * selected Products ("Job Order - To allow choosing of multiple
 * Products"). Mirrors App\Models\ContractProduct's shape.
 */
class JobOrderProduct extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['job_order_id', 'product_id'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
