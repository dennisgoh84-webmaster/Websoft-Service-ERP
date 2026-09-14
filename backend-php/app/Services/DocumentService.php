<?php

namespace App\Services;

use App\Exceptions\DocumentFileError;
use App\Models\DocumentAttachment;
use App\Models\DocumentSignature;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Generic eDocument attachment and eSignature service layer. Mirrors
 * backend/app/services/documents.py function-for-function (snake_case
 * -> camelCase, per docs/php-conversion-plan.md's naming rules).
 *
 * Handles file upload/download for document attachments and
 * electronic signature capture for any document type in the system.
 *
 * Named DocumentService rather than `Documents` so it reads as a
 * service the way every other one here does (BillingService,
 * PayablesService, QuotationService) -- the Python module is
 * `app/services/documents.py`.
 */
class DocumentService
{
    /** 20MB per file (confirmed 2026-09-12 with Dennis). */
    public const MAX_FILE_SIZE = 20 * 1024 * 1024;

    /**
     * <uploads_dir>/<company_id>/docs/<entity_type>/<entity_id>/ --
     * the exact layout backend/app/services/documents.py's
     * _doc_upload_dir() writes.
     */
    private static function docUploadDir(string $companyId, string $entityType, string $entityId): string
    {
        $dir = rtrim((string) config('websoft.uploads_dir'), '/')
            ."/{$companyId}/docs/{$entityType}/{$entityId}";

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /**
     * Save a file to disk and create a DB record.
     *
     * Any file TYPE is accepted -- deliberately, matching the Python
     * source and the 2026-09-12 decision ("any file type"). The only
     * limit is the 20MB size cap below.
     *
     * @throws DocumentFileError when the 20MB limit is exceeded.
     */
    public static function uploadAttachment(
        string $companyId,
        string $entityType,
        string $entityId,
        string $uploadedByUserId,
        string $originalFilename,
        string $contentType,
        string $data,
        ?string $description = null,
    ): DocumentAttachment {
        $size = strlen($data);
        if ($size > self::MAX_FILE_SIZE) {
            throw new DocumentFileError(sprintf('File exceeds 20MB limit (%s bytes).', number_format($size)));
        }

        $attachmentId = (string) Str::uuid();
        // Python: Path(original_filename).suffix.lower() or "" -- the
        // final ".ext" only, lowercased, empty when there is none.
        $ext = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $storedName = $ext === '' ? $attachmentId : $attachmentId.'.'.strtolower($ext);

        $destDir = self::docUploadDir($companyId, $entityType, $entityId);
        file_put_contents($destDir.'/'.$storedName, $data);

        // The id is allocated up front (it names the file on disk), so
        // the row is built and saved rather than mass-assigned -- `id`
        // is deliberately not fillable on any model here.
        $attachment = new DocumentAttachment([
            'company_id' => $companyId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'uploaded_by_user_id' => $uploadedByUserId,
            'original_filename' => $originalFilename,
            'stored_filename' => $storedName,
            'content_type' => $contentType,
            'file_size_bytes' => $size,
            'description' => $description,
        ]);
        $attachment->id = $attachmentId;
        $attachment->save();

        return $attachment->refresh();
    }

    /** @return Collection<int, DocumentAttachment> */
    public static function listAttachments(string $companyId, string $entityType, string $entityId)
    {
        return DocumentAttachment::where('company_id', $companyId)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('is_deleted', false)
            ->orderBy('uploaded_at')
            ->get();
    }

    /** The file's absolute path, or null when it is not on disk. */
    public static function attachmentFilePath(DocumentAttachment $attachment): ?string
    {
        $path = rtrim((string) config('websoft.uploads_dir'), '/')
            ."/{$attachment->company_id}/docs/{$attachment->entity_type}/{$attachment->entity_id}/{$attachment->stored_filename}";

        return is_file($path) ? $path : null;
    }

    public static function softDeleteAttachment(DocumentAttachment $attachment): void
    {
        $attachment->is_deleted = true;
        $attachment->save();
    }

    // -- eSignature --------------------------------------------------

    public static function addSignature(
        string $companyId,
        string $entityType,
        string $entityId,
        string $signerUserId,
        string $signerName,
        string $signatureDataUri,
        ?string $roleLabel = null,
    ): DocumentSignature {
        return DocumentSignature::create([
            'company_id' => $companyId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'signer_user_id' => $signerUserId,
            'signer_name' => $signerName,
            'signature_data_uri' => $signatureDataUri,
            'role_label' => $roleLabel,
        ]);
    }

    /** @return Collection<int, DocumentSignature> */
    public static function listSignatures(string $companyId, string $entityType, string $entityId)
    {
        return DocumentSignature::where('company_id', $companyId)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('is_deleted', false)
            ->orderBy('signed_at')
            ->get();
    }
}
