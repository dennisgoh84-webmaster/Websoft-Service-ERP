<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * An electronic signature on any document. Mirrors
 * backend/app/models/documents.py's DocumentSignature.
 *
 * Stores a drawn signature (PNG data URI from a canvas), the signer's
 * typed name, and a timestamp. Multiple signatures per document are
 * supported (e.g. preparer + approver can both sign). Same approach
 * as the mobile app's Service Record sign-off, available on any
 * document type.
 *
 * Soft-delete only, same as DocumentAttachment.
 *
 * The entity types this can attach to are the same list as
 * DocumentAttachment::ENTITY_TYPES (one shared Python enum,
 * DocumentEntityType) -- kept there rather than duplicated here.
 */
class DocumentSignature extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'entity_type', 'entity_id', 'signer_user_id',
        'signer_name', 'signature_data_uri', 'role_label', 'is_deleted',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
        'is_deleted' => 'boolean',
    ];
}
