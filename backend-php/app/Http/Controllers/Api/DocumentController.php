<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Exceptions\DocumentFileError;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\DocumentAttachment;
use App\Models\DocumentSignature;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\DocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Generic eDocument attachment and eSignature endpoints. Mirrors
 * backend/app/routers/documents.py 1:1.
 *
 * Any document type in the system (quotation, invoice, PO, PV, SR,
 * etc.) can carry file attachments and drawn electronic signatures
 * via these endpoints. Company-scoped; VIEW access to
 * `core_administration` to read, EDIT to mutate -- the same single
 * MODULE constant and levels the Python router uses (it deliberately
 * gates on one module rather than the parent document's own module,
 * so one panel component works on every document page).
 *
 * Upload: multipart/form-data with a 'file' part + optional
 * description. Download: streams the file back with its original
 * content type. Signatures: JSON body with the drawn signature data
 * URI + signer name.
 *
 * FINDING (flagged, not "fixed" in backend/, which this work never
 * touches): the Python router passes a dict into audit.record()'s
 * `details` parameter, which its own signature types `str | None` and
 * whose column is Text. App\Services\Audit::record() types it
 * `?string`, so the identical payload is JSON-encoded here -- the
 * evident intent, and the same information either way. See
 * docs/php-conversion-plan.md.
 *
 * KNOWN GAP (not silently papered over): this module is the
 * attachment/signature half of "Documents" only. The `.docx` export
 * and "Email <document>" endpoints that Service Records, Invoices,
 * Purchase Orders and Quotations each flag as awaiting "the Documents
 * module's mailer wiring" are NOT unblocked by this conversion --
 * that wiring lives in three separate Python services this module
 * does not touch or depend on (`app/services/mailer.py`,
 * `app/services/pdf_convert.py`, `app/services/docx_forms.py`, tied
 * together by `app/services/document_email.py`). Those remain
 * unconverted; the earlier gap notes' attribution to "the Documents
 * module" is corrected in docs/php-conversion-plan.md.
 */
class DocumentController extends Controller
{
    private const MODULE = 'core_administration';

    /**
     * Python declares `entity_type: DocumentEntityType` as a path
     * parameter, so FastAPI rejects an unknown value with a 422 before
     * the handler runs. Laravel has no equivalent path-level coercion,
     * so the same check is explicit here -- and returns the same 422.
     */
    private function requireKnownEntityType(string $entityType): string
    {
        if (! in_array($entityType, DocumentAttachment::ENTITY_TYPES, true)) {
            throw new ApiException(422, "Unknown document entity type '{$entityType}'.");
        }

        return $entityType;
    }

    private function presentAttachment(DocumentAttachment $a): array
    {
        return [
            'id' => $a->id,
            'company_id' => $a->company_id,
            'entity_type' => $a->entity_type,
            'entity_id' => $a->entity_id,
            'uploaded_by_user_id' => $a->uploaded_by_user_id,
            'original_filename' => $a->original_filename,
            'content_type' => $a->content_type,
            'file_size_bytes' => $a->file_size_bytes,
            'description' => $a->description,
            'uploaded_at' => $a->uploaded_at?->toIso8601String(),
        ];
    }

    /**
     * `signature_data_uri` is deliberately NOT returned -- Python's
     * DocumentSignatureOut leaves it out too, so a signature list
     * stays small and the drawn image is never re-served to a client
     * that only needs to know who signed and when.
     */
    private function presentSignature(DocumentSignature $s): array
    {
        return [
            'id' => $s->id,
            'company_id' => $s->company_id,
            'entity_type' => $s->entity_type,
            'entity_id' => $s->entity_id,
            'signer_user_id' => $s->signer_user_id,
            'signer_name' => $s->signer_name,
            'role_label' => $s->role_label,
            'signed_at' => $s->signed_at?->toIso8601String(),
        ];
    }

    private function attachmentOrFail(string $companyId, string $entityType, string $entityId, string $attachmentId): DocumentAttachment
    {
        $attachment = DocumentAttachment::find($attachmentId);
        if (! $attachment
            || $attachment->company_id !== $companyId
            || $attachment->entity_type !== $entityType
            || $attachment->entity_id !== $entityId
            || $attachment->is_deleted
        ) {
            throw new ApiException(404, 'Attachment not found');
        }

        return $attachment;
    }

    /** Upload a file attachment to any document. */
    public function uploadAttachment(Request $request, string $entityType, string $entityId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');
        $this->requireKnownEntityType($entityType);

        $request->validate([
            'file' => 'required|file',
            'description' => 'sometimes|nullable|string|max:500',
        ]);

        $file = $request->file('file');
        // Python reads the whole upload into memory and measures it
        // there; the equivalent here reads the temp file the same way,
        // so the 20MB rule lives in one place (DocumentService) rather
        // than being split between a framework rule and the service.
        $data = file_get_contents($file->getRealPath());

        try {
            $attachment = DB::transaction(function () use ($user, $entityType, $entityId, $file, $data, $request) {
                $attachment = DocumentService::uploadAttachment(
                    companyId: $user->company_id,
                    entityType: $entityType,
                    entityId: $entityId,
                    uploadedByUserId: $user->id,
                    // Python: `file.filename or "upload"` /
                    // `file.content_type or "application/octet-stream"`.
                    originalFilename: $file->getClientOriginalName() ?: 'upload',
                    contentType: $file->getClientMimeType() ?: 'application/octet-stream',
                    data: $data,
                    description: $request->input('description'),
                );

                Audit::record(
                    entityType: $entityType,
                    entityId: $entityId,
                    action: 'document_attachment_upload',
                    actorUserId: $user->id,
                    details: json_encode([
                        'filename' => $attachment->original_filename,
                        'size' => $attachment->file_size_bytes,
                    ]),
                );

                return $attachment;
            });
        } catch (DocumentFileError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->presentAttachment($attachment), 201);
    }

    /** List all active attachments for a document. */
    public function listAttachments(Request $request, string $entityType, string $entityId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');
        $this->requireKnownEntityType($entityType);

        $rows = DocumentService::listAttachments($user->company_id, $entityType, $entityId);

        return response()->json($rows->map(fn (DocumentAttachment $a) => $this->presentAttachment($a))->all());
    }

    /** Download an attachment file. */
    public function downloadAttachment(Request $request, string $entityType, string $entityId, string $attachmentId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');
        $this->requireKnownEntityType($entityType);

        $attachment = $this->attachmentOrFail($user->company_id, $entityType, $entityId, $attachmentId);

        $path = DocumentService::attachmentFilePath($attachment);
        if ($path === null) {
            throw new ApiException(404, 'File not found on disk');
        }

        return response()->download($path, $attachment->original_filename, [
            'Content-Type' => $attachment->content_type,
        ]);
    }

    /** Soft-delete an attachment -- the file itself is never removed from disk. */
    public function deleteAttachment(Request $request, string $entityType, string $entityId, string $attachmentId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');
        $this->requireKnownEntityType($entityType);

        $attachment = $this->attachmentOrFail($user->company_id, $entityType, $entityId, $attachmentId);

        DB::transaction(function () use ($attachment, $user, $entityType, $entityId) {
            DocumentService::softDeleteAttachment($attachment);
            Audit::record(
                entityType: $entityType,
                entityId: $entityId,
                action: 'document_attachment_delete',
                actorUserId: $user->id,
                details: json_encode(['filename' => $attachment->original_filename]),
            );
        });

        return response()->noContent();
    }

    // -- eSignature --------------------------------------------------

    /** Add a drawn electronic signature to a document. */
    public function addSignature(Request $request, string $entityType, string $entityId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');
        $this->requireKnownEntityType($entityType);

        // Mirrors DocumentSignatureCreate. `entity_type`/`entity_id`
        // are also present in the body the frontend sends, but the
        // path parameters are what the Python handler actually uses,
        // so they are ignored here too.
        $data = $request->validate([
            'signer_name' => 'required|string|min:1|max:255',
            'signature_data_uri' => 'required|string|min:1',
            'role_label' => 'sometimes|nullable|string|max:100',
        ]);

        $signature = DB::transaction(function () use ($user, $entityType, $entityId, $data) {
            $sig = DocumentService::addSignature(
                companyId: $user->company_id,
                entityType: $entityType,
                entityId: $entityId,
                signerUserId: $user->id,
                signerName: $data['signer_name'],
                signatureDataUri: $data['signature_data_uri'],
                roleLabel: $data['role_label'] ?? null,
            );

            Audit::record(
                entityType: $entityType,
                entityId: $entityId,
                action: 'document_signature_add',
                actorUserId: $user->id,
                details: json_encode([
                    'signer_name' => $data['signer_name'],
                    'role_label' => $data['role_label'] ?? null,
                ]),
            );

            return $sig;
        });

        return response()->json($this->presentSignature($signature), 201);
    }

    /** List all active signatures for a document. */
    public function listSignatures(Request $request, string $entityType, string $entityId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');
        $this->requireKnownEntityType($entityType);

        $rows = DocumentService::listSignatures($user->company_id, $entityType, $entityId);

        return response()->json($rows->map(fn (DocumentSignature $s) => $this->presentSignature($s))->all());
    }
}
