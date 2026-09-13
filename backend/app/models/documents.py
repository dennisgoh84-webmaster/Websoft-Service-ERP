"""
Generic eDocument attachments and eSignature for every document type.

Built 2026-09-12 (planned-work.md #3).

eDocument: any file type, 20MB per file, unlimited count per document.
Files stored on disk under UPLOADS_DIR/<company_id>/docs/<entity_type>/<entity_id>/

eSignature: drawn signature canvas (PNG data URI) + typed signer name +
timestamp. Same approach as the mobile app's Service Record sign-off,
now available on any document type.

Both are soft-delete (never permanently removed, same posture as every
other record in this system).

Entity types that can carry attachments and signatures:
  quotation, invoice, receipt_voucher, payment_voucher,
  purchase_order, supplier_invoice, journal_entry,
  job_order, service_record, contract, incident
"""
import enum
import uuid
from datetime import datetime

from sqlalchemy import Boolean, DateTime, Enum, ForeignKey, Integer, String, Text, func
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base


class DocumentEntityType(str, enum.Enum):
    """Every document type that can carry attachments and signatures."""
    QUOTATION = "quotation"
    INVOICE = "invoice"
    RECEIPT_VOUCHER = "receipt_voucher"
    PAYMENT_VOUCHER = "payment_voucher"
    PURCHASE_ORDER = "purchase_order"
    SUPPLIER_INVOICE = "supplier_invoice"
    JOURNAL_ENTRY = "journal_entry"
    JOB_ORDER = "job_order"
    SERVICE_RECORD = "service_record"
    CONTRACT = "contract"
    INCIDENT = "incident"
    COMMISSION_PAYOUT = "commission_payout"


class DocumentAttachment(Base):
    """A file attached to any document in the system.

    Files are stored on disk under
    UPLOADS_DIR/<company_id>/docs/<entity_type>/<entity_id>/<attachment_id>.<ext>

    Any file type is accepted; max 20MB per file; unlimited count per
    document (confirmed 2026-09-12 with Dennis).
    """

    __tablename__ = "document_attachments"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    company_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("companies.id"), nullable=False
    )
    entity_type: Mapped[DocumentEntityType] = mapped_column(
        Enum(DocumentEntityType, name="document_entity_type"), nullable=False
    )
    entity_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), nullable=False, index=True)

    uploaded_by_user_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("users.id"), nullable=False
    )

    original_filename: Mapped[str] = mapped_column(String(500), nullable=False)
    stored_filename: Mapped[str] = mapped_column(String(500), nullable=False)
    content_type: Mapped[str] = mapped_column(String(200), nullable=False)
    file_size_bytes: Mapped[int] = mapped_column(Integer, nullable=False)
    description: Mapped[str | None] = mapped_column(String(500), nullable=True)

    uploaded_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )

    # Soft-delete: never permanently removed
    is_deleted: Mapped[bool] = mapped_column(Boolean, default=False, nullable=False)


class DocumentSignature(Base):
    """An electronic signature on any document.

    Stores a drawn signature (PNG data URI from canvas), the signer's
    typed name, and a timestamp. Multiple signatures per document are
    supported (e.g. preparer + approver can both sign).
    """

    __tablename__ = "document_signatures"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    company_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("companies.id"), nullable=False
    )
    entity_type: Mapped[DocumentEntityType] = mapped_column(
        Enum(DocumentEntityType, name="document_entity_type"), nullable=False
    )
    entity_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), nullable=False, index=True)

    signer_user_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("users.id"), nullable=False
    )
    signer_name: Mapped[str] = mapped_column(String(255), nullable=False)
    signature_data_uri: Mapped[str] = mapped_column(Text, nullable=False)
    role_label: Mapped[str | None] = mapped_column(
        String(100), nullable=True
    )  # e.g. "Prepared by", "Approved by", "Authorized signatory"

    signed_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )

    # Soft-delete
    is_deleted: Mapped[bool] = mapped_column(Boolean, default=False, nullable=False)
