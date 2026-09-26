<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One document behind a saved GST Calculation, as it stood when calculated. */
class GstReturnLine extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    public const OUTPUT = 'output';

    public const INPUT = 'input';

    protected $fillable = [
        'gst_return_id', 'direction', 'document_type', 'document_id', 'document_number', 'document_date',
        'party_id', 'party_name', 'tax_code', 'box', 'net_sgd', 'gst_sgd',
    ];

    protected $casts = [
        'document_date' => 'date',
    ];

    public function gstReturn(): BelongsTo
    {
        return $this->belongsTo(GstReturn::class);
    }
}
