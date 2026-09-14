<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-company, per-document-kind override of the running number's
 * prefix and digit padding. Mirrors
 * backend/app/services/numbering.py's DocumentNumberFormat. Not yet
 * exposed by a controller (Document Control isn't converted) -- a
 * doc_kind with no row here uses Numbering::PREFIXES's built-in default.
 */
class DocumentNumberFormat extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['company_id', 'doc_kind', 'prefix', 'number_length', 'include_year'];

    protected $casts = ['number_length' => 'integer', 'include_year' => 'boolean'];
}
