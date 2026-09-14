<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ordered task/step in a Product's Job Implementation Template.
 * See App\Models\ProductImplementationTemplate.
 */
class ProductImplementationTemplateTask extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['template_id', 'task_name', 'description', 'sort_order'];

    protected $casts = [
        'sort_order' => 'integer',
        'created_at' => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(ProductImplementationTemplate::class, 'template_id');
    }
}
