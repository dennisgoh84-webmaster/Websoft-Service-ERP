<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The customer's sign-off on a Service Record: a finger-drawn
 * signature, a typed name, and a watermarked chop photo. Mirrors
 * backend/app/models/attachments.py's ServiceRecordSignoff.
 *
 * One per Service Record, enforced by a unique constraint -- a
 * sign-off is the customer's acceptance, so it is captured once.
 */
class ServiceRecordSignoff extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'service_record_id', 'signer_name', 'signature_data_uri',
        'chop_attachment_id', 'signed_by_user_id', 'signed_at',
    ];

    protected $casts = ['signed_at' => 'datetime'];

    public function serviceRecord(): BelongsTo
    {
        return $this->belongsTo(ServiceRecord::class);
    }

    public function chopAttachment(): BelongsTo
    {
        return $this->belongsTo(ServiceRecordAttachment::class, 'chop_attachment_id');
    }
}
