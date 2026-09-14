"""
Chart of accounts.

Confirmed with Dennis (2026-09-10): start from a conventional Singapore
SME chart and adjust it, rather than importing Odoo's. The seeded
accounts in scripts/seed_demo.py are therefore a STARTING POINT, not a
decided chart -- every account can be renamed, added or deactivated from
the Chart of Accounts screen.

This stage establishes the account structure only. Posting AR/AP/billing
transactions into a general ledger against these accounts comes with the
Finance / Accounting module; nothing posts yet, so no assumption is made
about which account each transaction hits.
"""
import enum
import uuid
from datetime import date, datetime
from decimal import Decimal

from sqlalchemy import (
    Boolean,
    Date,
    DateTime,
    Enum,
    ForeignKey,
    Index,
    Numeric,
    String,
    Text,
    UniqueConstraint,
    func,
    text,
)
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base


class AccountType(str, enum.Enum):
    """The five standard account classes. An account's type decides which
    financial statement it belongs to and its normal balance."""

    ASSET = "asset"
    LIABILITY = "liability"
    EQUITY = "equity"
    REVENUE = "revenue"
    EXPENSE = "expense"


class GLType(Base):
    """A finer classification within one of the 5 AccountType classes
    (e.g. Asset -> "Bank", "Fixed Asset", "Current Asset") -- purely a
    reporting/grouping label an account can optionally carry. Adding or
    renaming a GL Type never touches account_type or the ledger itself."""

    __tablename__ = "gl_types"
    __table_args__ = (UniqueConstraint("company_id", "code", name="uq_gl_type_code"),)

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    code: Mapped[str] = mapped_column(String(20), nullable=False)
    name: Mapped[str] = mapped_column(String(100), nullable=False)
    account_type: Mapped[AccountType] = mapped_column(
        Enum(AccountType, name="account_type"), nullable=False
    )
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)


class Account(Base):
    """One line of the chart of accounts."""

    __tablename__ = "accounts"
    __table_args__ = (UniqueConstraint("company_id", "code", name="uq_account_code"),)

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    # Chart of accounts is per company, like everything else.
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    code: Mapped[str] = mapped_column(String(20), nullable=False)
    name: Mapped[str] = mapped_column(String(200), nullable=False)
    account_type: Mapped[AccountType] = mapped_column(
        Enum(AccountType, name="account_type"), nullable=False
    )
    # Optional finer classification (see GLType) -- purely additive, never
    # required, so every existing account keeps working unclassified.
    gl_type_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("gl_types.id"), nullable=True)
    description: Mapped[str | None] = mapped_column(Text, nullable=True)
    # Retired rather than deleted -- an account that has been posted to
    # must remain for the history to stay readable.
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


class VoucherType(str, enum.Enum):
    """What kind of document a ledger entry came from. Vouchers are the
    unit of entry: money in (RV), money out (PV), and manual adjustments
    (JV)."""

    JOURNAL = "journal"  # JV -- manual double entry
    RECEIPT = "receipt"  # RV -- money received from a customer
    PAYMENT = "payment"  # PV -- money paid to a supplier
    SALES_INVOICE = "sales_invoice"
    PURCHASE_INVOICE = "purchase_invoice"


class JournalStatus(str, enum.Enum):
    DRAFT = "draft"
    POSTED = "posted"
    REVERSED = "reversed"


class JournalEntry(Base):
    """One voucher in the general ledger.

    Double entry is enforced: an entry cannot be posted unless its debits
    equal its credits. A posted entry is immutable -- corrections are
    made by REVERSING it (which writes an equal and opposite entry),
    never by editing or deleting, so the ledger keeps a complete history
    per CLAUDE.md's never-delete-financial-records rule.
    """

    __tablename__ = "journal_entries"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    voucher_number: Mapped[str] = mapped_column(String(50), nullable=False, index=True)
    voucher_type: Mapped[VoucherType] = mapped_column(
        Enum(VoucherType, name="voucher_type"), default=VoucherType.JOURNAL
    )
    entry_date: Mapped[date] = mapped_column(Date, nullable=False)
    narration: Mapped[str] = mapped_column(String(500), nullable=False)
    status: Mapped[JournalStatus] = mapped_column(
        Enum(JournalStatus, name="journal_status"), default=JournalStatus.DRAFT
    )

    # A document has at most one *live* posting: the (source_type,
    # source_id) pair is unique among non-reversed entries, so the
    # posting service cannot double-post and an UNGL'd document can be
    # posted again (gl-posting-design.md §4.7). The literal is the enum
    # member NAME because journal_status is a native enum that stores
    # names, not values.
    __table_args__ = (
        Index(
            "uq_journal_entries_live_source",
            "source_type",
            "source_id",
            unique=True,
            postgresql_where=text("status <> 'REVERSED' AND source_type IS NOT NULL"),
        ),
    )

    # Where this entry came from, when it wasn't keyed by hand (e.g. the
    # receipt voucher that generated it).
    source_type: Mapped[str | None] = mapped_column(String(50), nullable=True)
    source_id: Mapped[uuid.UUID | None] = mapped_column(UUID(as_uuid=True), nullable=True)
    # Set on the reversal entry, pointing at what it reverses.
    reverses_entry_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("journal_entries.id"), nullable=True
    )

    created_by_user_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("users.id"), nullable=True
    )
    posted_by_user_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("users.id"), nullable=True
    )
    posted_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())

    lines: Mapped[list["JournalLine"]] = relationship(
        back_populates="entry", cascade="all, delete-orphan"
    )

    @property
    def total_debit(self) -> Decimal:
        return sum((Decimal(l.debit_sgd) for l in self.lines), start=Decimal("0.00"))

    @property
    def total_credit(self) -> Decimal:
        return sum((Decimal(l.credit_sgd) for l in self.lines), start=Decimal("0.00"))

    @property
    def is_balanced(self) -> bool:
        return self.total_debit == self.total_credit and self.total_debit > Decimal("0.00")


class JournalLine(Base):
    """One side of a voucher: an account, and either a debit or a credit.

    A line carries one or the other, never both -- keeping them separate
    columns (rather than one signed amount) is how accountants read a
    ledger, and makes the balance check obvious.
    """

    __tablename__ = "journal_lines"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    entry_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("journal_entries.id"), nullable=False)
    account_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("accounts.id"), nullable=False)
    debit_sgd: Mapped[Decimal] = mapped_column(Numeric(12, 2), nullable=False, default=0)
    credit_sgd: Mapped[Decimal] = mapped_column(Numeric(12, 2), nullable=False, default=0)
    description: Mapped[str | None] = mapped_column(String(500), nullable=True)

    entry: Mapped["JournalEntry"] = relationship(back_populates="lines")
    account: Mapped["Account"] = relationship()
