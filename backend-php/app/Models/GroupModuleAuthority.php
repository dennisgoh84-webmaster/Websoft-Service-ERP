<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (group, module): the access level that group has on that
 * module. Absence of a row means AccessLevel::NONE. Mirrors
 * backend/app/models/groups.py's GroupModuleAuthority.
 */
class GroupModuleAuthority extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'group_module_authorities';

    public $timestamps = false;

    protected $fillable = ['group_id', 'module_key', 'access_level'];

    protected $casts = ['updated_at' => 'datetime'];

    // Access levels, in ascending order -- mirrors
    // backend/app/models/groups.py's AccessLevel / ACCESS_LEVEL_ORDER.
    public const NONE = 'none';

    public const VIEW = 'view';

    public const EDIT = 'edit';

    public const FULL = 'full';

    public const LEVEL_ORDER = [
        self::NONE => 0,
        self::VIEW => 1,
        self::EDIT => 2,
        self::FULL => 3,
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
