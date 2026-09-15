<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A finer classification within one of the 5 AccountType classes
 * (e.g. Asset -> "Bank", "Fixed Asset", "Current Asset") -- purely a
 * reporting/grouping label an account can optionally carry. Adding or
 * renaming a GL Type never touches account_type or the ledger itself.
 *
 * Mirrors backend/app/models/accounting.py's GLType.
 */
class GLType extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    protected $table = 'gl_types';

    protected $fillable = ['company_id', 'code', 'name', 'account_type', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected $casts = ['is_active' => 'boolean'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
