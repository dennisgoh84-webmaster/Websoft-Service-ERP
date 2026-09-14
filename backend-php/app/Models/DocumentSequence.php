<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/** Last number issued for a (company, document kind, year). Mirrors backend/app/services/numbering.py's DocumentSequence. */
class DocumentSequence extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['company_id', 'doc_kind', 'year', 'last_number'];

    protected $casts = ['year' => 'integer', 'last_number' => 'integer'];
}
