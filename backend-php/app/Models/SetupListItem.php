<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * General reference-data lookups: Nationality, Country, State, Area
 * Code, Currency codes, Industry. Mirrors
 * backend/app/models/setup.py's SetupListItem.
 *
 * These are universal facts (a country's name does not differ per
 * company), so -- unlike almost everything else in this app --
 * SetupListItem rows are NOT scoped by company_id; every company shares
 * one global list per list_type.
 *
 * One generic table rather than five near-identical ones, per
 * CLAUDE.md's "modular and maintainable": a new kind of simple code
 * list is a data row here, not a new table and migration.
 */
class SetupListItem extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    public const TYPE_NATIONALITY = 'nationality';

    public const TYPE_COUNTRY = 'country';

    /** parent_code = the owning Country's code. */
    public const TYPE_STATE = 'state';

    public const TYPE_AREA_CODE = 'area_code';

    public const TYPE_CURRENCY = 'currency';

    /** Confirmed 2026-09-11: customer grouping by industry. */
    public const TYPE_INDUSTRY = 'industry';

    public const TYPES = [
        self::TYPE_NATIONALITY,
        self::TYPE_COUNTRY,
        self::TYPE_STATE,
        self::TYPE_AREA_CODE,
        self::TYPE_CURRENCY,
        self::TYPE_INDUSTRY,
    ];

    protected $fillable = ['list_type', 'code', 'name', 'parent_code', 'sort_order', 'is_active'];

    protected $attributes = ['sort_order' => 0, 'is_active' => true];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];
}
