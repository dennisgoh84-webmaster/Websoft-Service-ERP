"""
Generic eDocument attachments and eSignature endpoints.

Any document type in the system (quotation, invoice, PO, PV, SR, etc.)
can carry file attachments and drawn electronic signatures via these
endpoints. Company-scoped; requires at least VIEW access to the parent
module (EDIT for mutations).

Upload: multipart/form-data with 'file' part + optional description.
Download: streams the file back with its original content type.
Signatures: JSON body with drawn signature data URI + signer name.
"""
import uuid

from fastapi import APIRouter, Depends, File, Form, HTTPException, UploadFile
from fastapi.responses import FileResponse
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.models.core import User
from app.models.documents import DocumentEntityType
from app.models.groups import AccessLevel
from app.schemas.schemas import (
    DocumentAttachmentOut,
    DocumentSignatureCreate,
    DocumentSignatureOut,
)
from app.services import audit
from app.services import documents as doc_svc
from app.services.authority import require_module_access

router = APIRouter(prefix="/api/documents", tags=["documents"])
MODULE = "core_administration"


@router.post(
    "/{entity_type}/{entity_id}/attachments",
    response_model=DocumentAttachmentOut,
    status_code=201,
)
async def upload_attachment(
    entity_type: DocumentEntityType,
    entity_id: uuid.UUID,
    file: UploadFile = File(...),
    description: str | None = Form(default=None),
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    """Upload a file attachment to any document."""
    data = await file.read()
    try:
        attachment = doc_svc.upload_attachment(
            db,
            company_id=current_user.company_id,
            entity_type=entity_type,
            entity_id=entity_id,
            uploaded_by_user_id=current_user.id,
            original_filename=file.filename or "upload",
            content_type=file.content_type or "application/octet-stream",
            data=data,
            description=description,
        )
    except doc_svc.DocumentFileError as exc:
        raise HTTPException(status_code=422, detail=str(exc))

    audit.record(
        db,
        user_id=current_user.id,
        company_id=current_user.company_id,
        action="document_attachment_upload",
        entity_type=entity_type.value,
        entity_id=entity_id,
        details={"filename": attachment.original_filename, "size": attachment.file_size_bytes},
    )
    db.commit()
    return attachment


@router.get(
    "/{entity_type}/{entity_id}/attachments",
    response_model=list[DocumentAttachmentOut],
)
def list_attachments(
    entity_type: DocumentEntityType,
    entity_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    """List all active attachments for a document."""
    return doc_svc.list_attachments(db, current_user.company_id, entity_type, entity_id)


@router.get("/{entity_type}/{entity_id}/attachments/{attachment_id}/download")
def download_attachment(
    entity_type: DocumentEntityType,
    entity_id: uuid.UUID,
    attachment_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    """Download an attachment file."""
    from app.models.documents import DocumentAttachment

    attachment = db.get(DocumentAttachment, attachment_id)
    if (
        not attachment
        or attachment.company_id != current_user.company_id
        or attachment.entity_type != entity_type
        or attachment.entity_id != entity_id
        or attachment.is_deleted
    ):
        raise HTTPException(status_code=404, detail="Attachment not found")

    file_path = doc_svc.get_attachment_file_path(attachment)
    if file_path is None:
        raise HTTPException(status_code=404, detail="File not found on disk")

    return FileResponse(
        path=str(file_path),
        media_type=attachment.content_type,
        filename=attachment.original_filename,
    )


@router.delete("/{entity_type}/{entity_id}/attachments/{attachment_id}", status_code=204)
def delete_attachment(
    entity_type: DocumentEntityType,
    entity_id: uuid.UUID,
    attachment_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    """Soft-delete an attachment."""
    from app.models.documents import DocumentAttachment

    attachment = db.get(DocumentAttachment, attachment_id)
    if (
        not attachment
        or attachment.company_id != current_user.company_id
        or attachment.entity_type != entity_type
        or attachment.entity_id != entity_id
        or attachment.is_deleted
    ):
        raise HTTPException(status_code=404, detail="Attachment not found")

    doc_svc.soft_delete_attachment(db, attachment)
    audit.record(
        db,
        user_id=current_user.id,
        company_id=current_user.company_id,
        action="document_attachment_delete",
        entity_type=entity_type.value,
        entity_id=entity_id,
        details={"filename": attachment.original_filename},
    )
    db.commit()


# ── eSignature ─────────────────────────────────────────────────────


@router.post(
    "/{entity_type}/{entity_id}/signatures",
    response_model=DocumentSignatureOut,
    status_code=201,
)
def add_signature(
    entity_type: DocumentEntityType,
    entity_id: uuid.UUID,
    body: DocumentSignatureCreate,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    """Add a drawn electronic signature to a document."""
    sig = doc_svc.add_signature(
        db,
        company_id=current_user.company_id,
        entity_type=entity_type,
        entity_id=entity_id,
        signer_user_id=current_user.id,
        signer_name=body.signer_name,
        signature_data_uri=body.signature_data_uri,
        role_label=body.role_label,
    )
    audit.record(
        db,
        user_id=current_user.id,
        company_id=current_user.company_id,
        action="document_signature_add",
        entity_type=entity_type.value,
        entity_id=entity_id,
        details={"signer_name": body.signer_name, "role_label": body.role_label},
    )
    db.commit()
    return sig


@router.get(
    "/{entity_type}/{entity_id}/signatures",
    response_model=list[DocumentSignatureOut],
)
def list_signatures(
    entity_type: DocumentEntityType,
    entity_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    """List all active signatures for a document."""
    return doc_svc.list_signatures(db, current_user.company_id, entity_type, entity_id)
