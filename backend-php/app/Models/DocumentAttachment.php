<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * A file attached to any document in the system. Mirrors
 * backend/app/models/documents.py's DocumentAttachment.
 *
 * Files are stored on disk under
 * <uploads_dir>/<company_id>/docs/<entity_type>/<entity_id>/<attachment_id>.<ext>
 * -- the identical layout the Python version writes, so the two
 * backends can be pointed at the same UPLOADS_DIR during the
 * conversion without either losing sight of the other's files.
 *
 * Any file type is accepted; max 20MB per file; unlimited count per
 * document (confirmed 2026-09-12 with Dennis).
 *
 * Soft-delete only -- never permanently removed, same posture as
 * every other record in this system (CLAUDE.md).
 */
class DocumentAttachment extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    /**
     * Every document type that can carry attachments and signatures.
     * Mirrors backend/app/models/documents.py's DocumentEntityType
     * enum member-for-member and value-for-value -- the frontend's
     * `DocumentEntityType` union in frontend/src/lib/api.ts is the
     * same list.
     */
    public const ENTITY_TYPES = [
        'quotation',
        'invoice',
        'receipt_voucher',
        'payment_voucher',
        'purchase_order',
        'supplier_invoice',
        'journal_entry',
        'job_order',
        'service_record',
        'contract',
        'incident',
        'commission_payout',
    ];

    protected $fillable = [
        'company_id', 'entity_type', 'entity_id', 'uploaded_by_user_id', 'uploaded_by_portal_user_id',
        'original_filename', 'stored_filename', 'content_type', 'file_size_bytes',
        'description', 'is_deleted',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'file_size_bytes' => 'integer',
        'is_deleted' => 'boolean',
    ];
}
