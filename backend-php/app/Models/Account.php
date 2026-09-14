<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the chart of accounts. Mirrors
 * backend/app/models/accounting.py's Account. The seeded chart
 * (DatabaseSeeder) is a conventional Singapore SME starting point
 * (confirmed approach, 2026-09-10), not a decided chart -- every
 * account can be renamed, added or retired from the Chart of Accounts
 * screen.
 *
 * NOT converted: GLType (a purely optional reporting sub-
 * classification a chart line can carry -- gl_type_id is never
 * referenced by posting.py itself, so this is scoped out; see
 * docs/php-conversion-plan.md).
 */
class Account extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    public const TYPE_ASSET = 'asset';

    public const TYPE_LIABILITY = 'liability';

    public const TYPE_EQUITY = 'equity';

    public const TYPE_REVENUE = 'revenue';

    public const TYPE_EXPENSE = 'expense';

    protected $fillable = ['company_id', 'code', 'name', 'account_type', 'description', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
