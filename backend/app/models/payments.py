"""
Accounts Receivable -- customer payments and how they are allocated
against invoices.

AR-001 (confirmed): allocation is **manual**. Finance decides which
invoices a receipt settles, based on the customer's remittance advice --
there is deliberately no automatic matching rule, because a payment that
doesn't tie exactly to one invoice is a judgement call.

A payment is therefore recorded first (money arrived), and allocated
second (what it settles). An unallocated balance is normal and visible --
it is money on account, not an error.
"""
import enum
import uuid
from datetime import date, datetime
from decimal import Decimal

from sqlalchemy import Date, DateTime, Enum, ForeignKey, Numeric, String, func
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base


class PaymentMethod(str, enum.Enum):
    BANK_TRANSFER = "bank_transfer"
    PAYNOW = "paynow"
    CHEQUE = "cheque"
    CASH = "cash"
    CREDIT_CARD = "credit_card"
    OTHER = "other"


class Payment(Base):
    """Money received from a customer. Recorded when it arrives; what it
    settles is decided separately (see PaymentAllocation)."""

    __tablename__ = "payments"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    customer_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("company_individuals.id"), nullable=False)

    # Receipt Voucher number (RV-YYYY-nnnn) -- the document reference
    # Finance and the customer both quote.
    voucher_number: Mapped[str] = mapped_column(String(50), nullable=False, index=True)
    payment_date: Mapped[date] = mapped_column(Date, nullable=False)
    amount_sgd: Mapped[Decimal] = mapped_column(Numeric(12, 2), nullable=False)
    method: Mapped[PaymentMethod] = mapped_column(
        Enum(PaymentMethod, name="payment_method"), default=PaymentMethod.BANK_TRANSFER
    )
    # Bank reference / cheque number / remittance advice reference.
    reference: Mapped[str | None] = mapped_column(String(200), nullable=True)
    notes: Mapped[str | None] = mapped_column(String(500), nullable=True)

    # Which of the company's bank accounts the money landed in (ACC-001,
    # docs/gl-posting-design.md §4.3). Drives both the GL debit (via
    # BankAccount.gl_account_id) and the explicit Bank step (ACC-002).
    # Nullable at the DB level only so the migration can back-fill
    # existing rows without inventing bank accounts for a company that
    # has none; the API requires it on every new receipt.
    bank_account_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("bank_accounts.id"), nullable=True
    )

    recorded_by_user_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("users.id"), nullable=True
    )
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())

    customer: Mapped["CompanyIndividual"] = relationship()  # noqa: F821
    allocations: Mapped[list["PaymentAllocation"]] = relationship(
        back_populates="payment", cascade="all, delete-orphan"
    )

    @property
    def allocated_sgd(self) -> Decimal:
        return sum(
            (Decimal(a.amount_sgd) for a in self.allocations), start=Decimal("0.00")
        )

    @property
    def unallocated_sgd(self) -> Decimal:
        """Money received but not yet applied to an invoice -- sits on the
        customer's account until Finance allocates it (AR-001)."""
        return Decimal(self.amount_sgd) - self.allocated_sgd


class PaymentAllocation(Base):
    """How much of one payment settles one invoice. Manual, per AR-001."""

    __tablename__ = "payment_allocations"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    payment_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("payments.id"), nullable=False)
    invoice_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("invoices.id"), nullable=False)
    amount_sgd: Mapped[Decimal] = mapped_column(Numeric(12, 2), nullable=False)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())

    payment: Mapped["Payment"] = relationship(back_populates="allocations")


class CommissionSettings(Base):
    """Singleton-per-company settings row for the commission report
    (2026-09-12, docs/open-business-decisions.md #32): confirmed formula
    is a flat percentage of gross profit, applied to the portion of an
    invoice a receipt has actually settled -- but the percentage itself
    is Dennis's to set, not a number to invent, so it lives here as a
    plain admin-editable rate rather than being hardcoded into the
    report. See app/services/reports.py's commission_rows."""

    __tablename__ = "commission_settings"

    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), primary_key=True)
    rate_percent: Mapped[Decimal] = mapped_column(Numeric(5, 2), nullable=False, default=0)
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )
