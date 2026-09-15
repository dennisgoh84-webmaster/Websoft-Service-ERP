<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A heading on one staff member's Ops Dashboard. */
class OpsTaskCategory extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'owner_user_id', 'name', 'cadence_label', 'sort_order', 'is_active',
    ];

    protected $attributes = ['sort_order' => 0, 'is_active' => true];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function tasks(): HasMany
    {
        return $this->hasMany(OpsTask::class, 'category_id');
    }
}
