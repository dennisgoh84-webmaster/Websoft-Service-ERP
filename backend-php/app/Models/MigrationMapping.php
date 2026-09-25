<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * The saved field mapping for one Internal Company + source system +
 * module, and its Field Gap sign-off (docs/data-migration.md). Every
 * column is either mapped to a field here, left out (`__skip__`), or a
 * Field Gap (`__new_field__`: needs a field added here first). Import
 * is locked until nothing is undecided or a Field Gap, and a FULL user
 * has signed the mapping off; changing it afterwards clears the
 * sign-off.
 */
class MigrationMapping extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    public const SKIP = '__skip__';

    public const NEW_FIELD = '__new_field__';

    protected $fillable = ['company_id', 'source', 'entity', 'mapping', 'headers', 'signed_off_at', 'signed_off_by_user_id', 'updated_at'];

    protected $casts = [
        'mapping' => 'array',
        'headers' => 'array',
        'signed_off_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
