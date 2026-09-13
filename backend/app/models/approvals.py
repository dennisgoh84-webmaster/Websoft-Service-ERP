"""
eApproval Master — generic, authority-based, value-gated, multi-staff
approval framework.

Built 2026-09-12 (planned-work.md #4).

Covers: Purchase Order, Payment Voucher, Service Record, Quotation.
Designed to absorb the one-off Service Record approval logic.

Key concepts:
- ApprovalAuthority: a named authority type (e.g. "PO Approval",
  "Bank Authority for DBS-001"). Can be configured to require any-one
  or all assigned staff to approve.
- ApprovalAuthorityMember: which users hold which authorities.
- ApprovalRule: binds an authority to a document type, optionally with
  a value threshold (e.g. PO > SGD 5000 needs director approval).
- ApprovalRequest: an actual approval request on a specific document.
- ApprovalDecision: each approver's individual decision (approve/reject)
  with timestamp and optional comment.
"""
import enum
import uuid
from datetime import datetime
from decimal import Decimal

from sqlalchemy import (
    Boolean,
    DateTime,
    Enum,
    ForeignKey,
    Integer,
    Numeric,
    String,
    Text,
    UniqueConstraint,
    func,
)
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base
from app.models.documents import DocumentEntityType


class ApprovalMode(str, enum.Enum):
    """Whether one or all assigned approvers must approve."""
    ANY_ONE = "any_one"
    ALL_MUST = "all_must"


class ApprovalStatus(str, enum.Enum):
    """Status of an approval request."""
    PENDING = "pending"
    APPROVED = "approved"
    REJECTED = "rejected"


class ApprovalDecisionValue(str, enum.Enum):
    """An individual approver's decision."""
    APPROVED = "approved"
    REJECTED = "rejected"


# ── Authority definition ───────────────────────────────────────────


class ApprovalAuthority(Base):
    """A named approval authority (e.g. "PO Approval", "Bank Authority
    for DBS Current Account").

    Authorities are company-scoped. Each authority specifies whether
    any-one or all assigned members must approve.
    """

    __tablename__ = "approval_authorities"
    __table_args__ = (
        UniqueConstraint("company_id", "name", name="uq_approval_authority_name"),
    )

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    company_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("companies.id"), nullable=False
    )
    name: Mapped[str] = mapped_column(String(200), nullable=False)
    description: Mapped[str | None] = mapped_column(String(500), nullable=True)
    mode: Mapped[ApprovalMode] = mapped_column(
        Enum(ApprovalMode, name="approval_mode"),
        nullable=False,
        default=ApprovalMode.ANY_ONE,
    )

    # Optional FK: for bank-specific authorities, ties to a specific
    # bank account. NULL for non-bank authorities.
    bank_account_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("bank_accounts.id"), nullable=True
    )

    is_active: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )

    members: Mapped[list["ApprovalAuthorityMember"]] = relationship(
        back_populates="authority", cascade="all, delete-orphan"
    )
    rules: Mapped[list["ApprovalRule"]] = relationship(
        back_populates="authority", cascade="all, delete-orphan"
    )


class ApprovalAuthorityMember(Base):
    """A user assigned to an approval authority."""

    __tablename__ = "approval_authority_members"
    __table_args__ = (
        UniqueConstraint("authority_id", "user_id", name="uq_approval_member"),
    )

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    authority_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("approval_authorities.id"), nullable=False
    )
    user_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("users.id"), nullable=False
    )
    added_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )

    authority: Mapped["ApprovalAuthority"] = relationship(back_populates="members")


# ── Rules: which authorities govern which documents ────────────────


class ApprovalRule(Base):
    """Binds an authority to a document type, optionally with a value
    threshold.

    Example rules:
    - "PO Approval" authority governs purchase_order when amount > 5000
    - "Bank Authority DBS" authority governs payment_voucher (no threshold)
    - "SR Approval" authority governs service_record (no threshold)
    """

    __tablename__ = "approval_rules"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    authority_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("approval_authorities.id"), nullable=False
    )
    entity_type: Mapped[DocumentEntityType] = mapped_column(
        Enum(DocumentEntityType, name="document_entity_type"), nullable=False
    )

    # Value threshold: if set, this rule only triggers when the
    # document's value >= threshold_amount. NULL = always applies.
    threshold_amount: Mapped[Decimal | None] = mapped_column(
        Numeric(15, 2), nullable=True
    )

    # Priority: lower number = higher priority. When multiple rules
    # match, the highest-priority rule's authority is used.
    priority: Mapped[int] = mapped_column(Integer, nullable=False, default=0)

    is_active: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )

    authority: Mapped["ApprovalAuthority"] = relationship(back_populates="rules")


# ── Approval requests and decisions ───────────────────────────────


class ApprovalRequest(Base):
    """A request for approval on a specific document instance.

    Created when a document is submitted for approval. The status
    reflects the overall outcome based on the authority's mode
    (any_one vs all_must).
    """

    __tablename__ = "approval_requests"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    company_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("companies.id"), nullable=False
    )
    entity_type: Mapped[DocumentEntityType] = mapped_column(
        Enum(DocumentEntityType, name="document_entity_type"), nullable=False
    )
    entity_id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), nullable=False, index=True
    )

    rule_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("approval_rules.id"), nullable=False
    )
    authority_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("approval_authorities.id"), nullable=False
    )

    status: Mapped[ApprovalStatus] = mapped_column(
        Enum(ApprovalStatus, name="approval_status"),
        nullable=False,
        default=ApprovalStatus.PENDING,
    )

    requested_by_user_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("users.id"), nullable=False
    )
    requested_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )
    resolved_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )

    decisions: Mapped[list["ApprovalDecision"]] = relationship(
        back_populates="request", cascade="all, delete-orphan"
    )
    rule: Mapped["ApprovalRule"] = relationship()
    authority: Mapped["ApprovalAuthority"] = relationship()


class ApprovalDecision(Base):
    """An individual approver's decision on an approval request."""

    __tablename__ = "approval_decisions"
    __table_args__ = (
        UniqueConstraint("request_id", "user_id", name="uq_approval_decision"),
    )

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    request_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("approval_requests.id"), nullable=False
    )
    user_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("users.id"), nullable=False
    )
    decision: Mapped[ApprovalDecisionValue] = mapped_column(
        Enum(ApprovalDecisionValue, name="approval_decision_value"), nullable=False
    )
    comment: Mapped[str | None] = mapped_column(Text, nullable=True)
    decided_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )

    request: Mapped["ApprovalRequest"] = relationship(back_populates="decisions")
