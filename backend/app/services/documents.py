"""
Generic eDocument attachment and eSignature service layer.

Handles file upload/download for document attachments and electronic
signature capture for any document type in the system.
"""
import uuid
from pathlib import Path

from sqlalchemy.orm import Session

from app.core.config import settings
from app.models.documents import DocumentAttachment, DocumentEntityType, DocumentSignature


MAX_FILE_SIZE = 20 * 1024 * 1024  # 20MB


class DocumentFileError(Exception):
    """A file upload/download rule was broken."""


def _doc_upload_dir(company_id: uuid.UUID, entity_type: str, entity_id: uuid.UUID) -> Path:
    d = Path(settings.uploads_dir) / str(company_id) / "docs" / entity_type / str(entity_id)
    d.mkdir(parents=True, exist_ok=True)
    return d


def upload_attachment(
    db: Session,
    *,
    company_id: uuid.UUID,
    entity_type: DocumentEntityType,
    entity_id: uuid.UUID,
    uploaded_by_user_id: uuid.UUID,
    original_filename: str,
    content_type: str,
    data: bytes,
    description: str | None = None,
) -> DocumentAttachment:
    """Save a file to disk and create a DB record."""
    if len(data) > MAX_FILE_SIZE:
        raise DocumentFileError(f"File exceeds 20MB limit ({len(data):,} bytes).")

    attachment_id = uuid.uuid4()
    ext = Path(original_filename).suffix.lower() or ""
    stored_name = f"{attachment_id}{ext}"

    dest_dir = _doc_upload_dir(company_id, entity_type.value, entity_id)
    (dest_dir / stored_name).write_bytes(data)

    attachment = DocumentAttachment(
        id=attachment_id,
        company_id=company_id,
        entity_type=entity_type,
        entity_id=entity_id,
        uploaded_by_user_id=uploaded_by_user_id,
        original_filename=original_filename,
        stored_filename=stored_name,
        content_type=content_type,
        file_size_bytes=len(data),
        description=description,
    )
    db.add(attachment)
    db.flush()
    return attachment


def list_attachments(
    db: Session,
    company_id: uuid.UUID,
    entity_type: DocumentEntityType,
    entity_id: uuid.UUID,
) -> list[DocumentAttachment]:
    return (
        db.query(DocumentAttachment)
        .filter(
            DocumentAttachment.company_id == company_id,
            DocumentAttachment.entity_type == entity_type,
            DocumentAttachment.entity_id == entity_id,
            DocumentAttachment.is_deleted.is_(False),
        )
        .order_by(DocumentAttachment.uploaded_at)
        .all()
    )


def get_attachment_file_path(attachment: DocumentAttachment) -> Path | None:
    p = (
        Path(settings.uploads_dir)
        / str(attachment.company_id)
        / "docs"
        / attachment.entity_type.value
        / str(attachment.entity_id)
        / attachment.stored_filename
    )
    return p if p.is_file() else None


def soft_delete_attachment(db: Session, attachment: DocumentAttachment) -> None:
    attachment.is_deleted = True


# ── eSignature ─────────────────────────────────────────────────────


def add_signature(
    db: Session,
    *,
    company_id: uuid.UUID,
    entity_type: DocumentEntityType,
    entity_id: uuid.UUID,
    signer_user_id: uuid.UUID,
    signer_name: str,
    signature_data_uri: str,
    role_label: str | None = None,
) -> DocumentSignature:
    sig = DocumentSignature(
        company_id=company_id,
        entity_type=entity_type,
        entity_id=entity_id,
        signer_user_id=signer_user_id,
        signer_name=signer_name,
        signature_data_uri=signature_data_uri,
        role_label=role_label,
    )
    db.add(sig)
    db.flush()
    return sig


def list_signatures(
    db: Session,
    company_id: uuid.UUID,
    entity_type: DocumentEntityType,
    entity_id: uuid.UUID,
) -> list[DocumentSignature]:
    return (
        db.query(DocumentSignature)
        .filter(
            DocumentSignature.company_id == company_id,
            DocumentSignature.entity_type == entity_type,
            DocumentSignature.entity_id == entity_id,
            DocumentSignature.is_deleted.is_(False),
        )
        .order_by(DocumentSignature.signed_at)
        .all()
    )
