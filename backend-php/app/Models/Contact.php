<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mirrors backend/app/models/company_individuals.py's Contact. */
class Contact extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'contacts';

    public $timestamps = false;

    protected $fillable = ['customer_id', 'name', 'email', 'phone', 'direct_line', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CompanyIndividual::class, 'customer_id');
    }
}
