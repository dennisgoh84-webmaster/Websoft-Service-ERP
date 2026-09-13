"""
Commission Management -- approval workflow (6.3), clawback (6.4),
and payout mechanism (6.5).

Built on top of the existing commission report (see
app/services/reports.py commission_rows): the report calculates what
commission *would be*, and this module turns those calculations into
discrete, approvable, payable records.

Key concepts:
- CommissionPayout: a monthly commission record for one salesperson.
  Created in DRAFT from the commission report, submitted for approval
  via eApproval, then marked PAID by Finance.
- CommissionPayoutType: EARNING (positive, from receipt-allocated GP)
  or CLAWBACK (negative, when a write-off reverses previously
  commissioned revenue).
- CommissionPayoutStatus: DRAFT → PENDING_APPROVAL → APPROVED → PAID
  (or CLAWED_BACK for records reversed by a clawback).
"""
import enum
import uuid
from datetime import date, datetime
from decimal import Decimal

from sqlalchemy import Date, DateTime, Enum, ForeignKey, Numeric, String, Text, func
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base


class CommissionPayoutType(str, enum.Enum):
    EARNING = "earning"
    CLAWBACK = "clawback"


class CommissionPayoutStatus(str, enum.Enum):
    DRAFT = "draft"
    PENDING_APPROVAL = "pending_approval"
    APPROVED = "approved"
    PAID = "paid"
    CANCELLED = "cancelled"


class CommissionPayout(Base):
    """A commission record for one salesperson for one month.

    amount_sgd is positive for EARNING, negative for CLAWBACK.
    Clawback records skip approval (they reduce what's owed, they
    don't need a second approval to take money back).
    """

    __tablename__ = "commission_payouts"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    company_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("companies.id"), nullable=False
    )

    # The payout number (CP-YYYY-nnnn) — the reference Finance quotes.
    payout_number: Mapped[str] = mapped_column(String(50), nullable=False, index=True)

    payout_type: Mapped[CommissionPayoutType] = mapped_column(
        Enum(CommissionPayoutType, name="commission_payout_type"),
        nullable=False,
        default=CommissionPayoutType.EARNING,
    )
    status: Mapped[CommissionPayoutStatus] = mapped_column(
        Enum(CommissionPayoutStatus, name="commission_payout_status"),
        nullable=False,
        default=CommissionPayoutStatus.DRAFT,
    )

    # Who earns this commission — the salesperson (Contract.sales_staff_id).
    sales_staff_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("users.id"), nullable=False
    )

    # Period this commission covers (YYYY-MM is the key, but storing
    # the first and last day of the month makes date-range queries easy).
    period_month: Mapped[str] = mapped_column(String(7), nullable=False)  # "YYYY-MM"
    period_start: Mapped[date] = mapped_column(Date, nullable=False)
    period_end: Mapped[date] = mapped_column(Date, nullable=False)

    # The commission amount (positive for earning, negative for clawback).
    amount_sgd: Mapped[Decimal] = mapped_column(Numeric(12, 2), nullable=False)
    # The rate that was used at the time of calculation.
    rate_percent: Mapped[Decimal] = mapped_column(Numeric(5, 2), nullable=False)

    # For clawback: which invoice was written off / reversed.
    clawback_invoice_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("invoices.id"), nullable=True
    )
    clawback_reason: Mapped[str | None] = mapped_column(String(500), nullable=True)

    # Approval tracking.
    submitted_by_user_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("users.id"), nullable=True
    )
    submitted_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )
    approved_by_user_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("users.id"), nullable=True
    )
    approved_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )

    # Payout tracking (6.5): Finance marks as paid.
    paid_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    paid_reference: Mapped[str | None] = mapped_column(String(200), nullable=True)
    paid_by_user_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("users.id"), nullable=True
    )

    notes: Mapped[str | None] = mapped_column(Text, nullable=True)

    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )
