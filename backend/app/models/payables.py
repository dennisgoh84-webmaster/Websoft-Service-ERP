"""
Accounts Payable -- purchase orders, supplier invoices (bills) and
supplier payments. The supplier itself is NOT modelled here: 2026-09-12
("when talking about supplier, remember to use the same company/
individual file, do not add or reinvent a new one again") folded the
former standalone Supplier table into CompanyIndividual (app/models/company_individuals.py)
as a role flag -- `CompanyIndividual.is_supplier`. Every `supplier_id` column
below is a foreign key to `company_individuals.id`; the name is kept as
`supplier_id`/`supplier` throughout this module (not renamed to
`customer_id`) purely so the AP-specific meaning stays obvious in this
file's own code, without implying a second, separate master record.

Confirmed rules this supports:
- PUR-001: purchase order approval is value-based. Below the company's
  threshold, procurement/finance approve directly; above it, the owner
  does. The threshold itself is undecided (open item 4.4), so it is a
  configurable field that, while unset, sends every PO to the owner.
- PUR-002: **2-way matching** -- a supplier invoice is matched against
  the purchase order only. There is deliberately no goods-receipt
  document or 3-way match.
- PUR-003: a supplier invoice that matches its PO is auto-approved for
  payment. A mismatch becomes an EXCEPTION and stops there: how
  mismatches are resolved is open item 4.5, so nothing is invented.
"""
import enum
import uuid
from datetime import date, datetime
from decimal import Decimal

from sqlalchemy import (
    Date,
    DateTime,
    Enum,
    ForeignKey,
    Numeric,
    String,
    func,
)
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base
from app.models.company_individuals import CompanyIndividual  # noqa: F401 -- used in string type hints below


class PurchaseOrderStatus(str, enum.Enum):
    DRAFT = "draft"
    PENDING_APPROVAL = "pending_approval"  # PUR-001: above the threshold
    APPROVED = "approved"
    CANCELLED = "cancelled"


class PurchaseOrder(Base):
    """What we committed to buy -- the document a supplier invoice is
    matched against under PUR-002."""

    __tablename__ = "purchase_orders"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    supplier_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("company_individuals.id"), nullable=False)
    po_number: Mapped[str] = mapped_column(String(50), nullable=False, index=True)
    order_date: Mapped[date] = mapped_column(Date, nullable=False)
    description: Mapped[str] = mapped_column(String(500), nullable=False)

    amount_sgd: Mapped[Decimal] = mapped_column(Numeric(12, 2), nullable=False)  # net
    gst_amount_sgd: Mapped[Decimal] = mapped_column(Numeric(12, 2), nullable=False, default=0)
    total_amount_sgd: Mapped[Decimal] = mapped_column(Numeric(12, 2), nullable=False, default=0)

    status: Mapped[PurchaseOrderStatus] = mapped_column(
        Enum(PurchaseOrderStatus, name="purchase_order_status"),
        default=PurchaseOrderStatus.DRAFT,
    )
    approved_by_user_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("users.id"), nullable=True
    )
    approved_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())

    supplier: Mapped["CompanyIndividual"] = relationship()
    # Bills raised against this PO -- "confirm and import to AP" (2026-09-12)
    # checks this to stop a PO being imported into AP twice.
    bills: Mapped[list["SupplierInvoice"]] = relationship(back_populates="purchase_order")


class BillMatchStatus(str, enum.Enum):
    """PUR-002 2-way match outcome."""

    NOT_MATCHED = "not_matched"  # no PO referenced
    MATCHED = "matched"  # agrees with the PO -> auto-approved (PUR-003)
    EXCEPTION = "exception"  # disagrees -- resolution is open item 4.5


class BillStatus(str, enum.Enum):
    AWAITING_MATCH = "awaiting_match"
    EXCEPTION = "exception"
    APPROVED = "approved"  # cleared for payment
    PARTIALLY_PAID = "partially_paid"
    PAID = "paid"


class SupplierInvoice(Base):
    """A bill received from a supplier."""

    __tablename__ = "supplier_invoices"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    supplier_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("company_individuals.id"), nullable=False)
    purchase_order_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("purchase_orders.id"), nullable=True
    )

    # Our internal reference, and the supplier's own invoice number.
    bill_number: Mapped[str] = mapped_column(String(50), nullable=False, index=True)
    supplier_invoice_no: Mapped[str | None] = mapped_column(String(100), nullable=True)

    invoice_date: Mapped[date] = mapped_column(Date, nullable=False)
    due_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    description: Mapped[str] = mapped_column(String(500), nullable=False)

    amount_sgd: Mapped[Decimal] = mapped_column(Numeric(12, 2), nullable=False)  # net
    # GST charged by the supplier -- input tax, recoverable, so it is
    # tracked separately from the cost itself.
    gst_amount_sgd: Mapped[Decimal] = mapped_column(Numeric(12, 2), nullable=False, default=0)
    total_amount_sgd: Mapped[Decimal] = mapped_column(Numeric(12, 2), nullable=False, default=0)
    amount_paid_sgd: Mapped[Decimal] = mapped_column(Numeric(12, 2), nullable=False, default=0)

    # Expense account the net amount posts to (gl-posting-design.md
    # §4.2). Optional: when unset the posting service uses the company's
    # default, 5000 Cost of services -- a pragmatic default called out in
    # ACC-001's design, not a decided rule.
    expense_account_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("accounts.id"), nullable=True
    )

    match_status: Mapped[BillMatchStatus] = mapped_column(
        Enum(BillMatchStatus, name="bill_match_status"), default=BillMatchStatus.NOT_MATCHED
    )
    match_note: Mapped[str | None] = mapped_column(String(500), nullable=True)
    status: Mapped[BillStatus] = mapped_column(
        Enum(BillStatus, name="bill_status"), default=BillStatus.AWAITING_MATCH
    )

    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())

    supplier: Mapped["CompanyIndividual"] = relationship()
    purchase_order: Mapped["PurchaseOrder | None"] = relationship(back_populates="bills")

    @property
    def outstanding_sgd(self) -> Decimal:
        return max(
            Decimal(self.total_amount_sgd) - Decimal(self.amount_paid_sgd), Decimal("0.00")
        )


class SupplierPayment(Base):
    """Money paid out to a supplier -- the Payment Voucher (PV)."""

    __tablename__ = "supplier_payments"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    supplier_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("company_individuals.id"), nullable=False)
    voucher_number: Mapped[str] = mapped_column(String(50), nullable=False, index=True)

    payment_date: Mapped[date] = mapped_column(Date, nullable=False)
    amount_sgd: Mapped[Decimal] = mapped_column(Numeric(12, 2), nullable=False)
    method: Mapped[str] = mapped_column(String(30), default="bank_transfer")
    reference: Mapped[str | None] = mapped_column(String(200), nullable=True)
    notes: Mapped[str | None] = mapped_column(String(500), nullable=True)

    # Which bank account the payment went out of (ACC-001, gl-posting-
    # design.md §4.4). Same nullable-for-backfill / required-at-API
    # posture as Payment.bank_account_id.
    bank_account_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("bank_accounts.id"), nullable=True
    )

    paid_by_user_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("users.id"), nullable=True
    )
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())

    supplier: Mapped["CompanyIndividual"] = relationship()
    allocations: Mapped[list["SupplierPaymentAllocation"]] = relationship(
        back_populates="payment", cascade="all, delete-orphan"
    )

    @property
    def allocated_sgd(self) -> Decimal:
        return sum((Decimal(a.amount_sgd) for a in self.allocations), start=Decimal("0.00"))

    @property
    def unallocated_sgd(self) -> Decimal:
        return Decimal(self.amount_sgd) - self.allocated_sgd


class SupplierPaymentAllocation(Base):
    """Which bills a payment voucher settles. Manual, mirroring AR-001 --
    deciding what a payment covers is Finance's call either way."""

    __tablename__ = "supplier_payment_allocations"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    payment_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("supplier_payments.id"), nullable=False
    )
    supplier_invoice_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("supplier_invoices.id"), nullable=False
    )
    amount_sgd: Mapped[Decimal] = mapped_column(Numeric(12, 2), nullable=False)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())

    payment: Mapped["SupplierPayment"] = relationship(back_populates="allocations")
