"""
Bank Master File, the Currency Rate Table, and the Bank Book (Bank
Transactions + Bank Reconciliation).

The master file and rate table are setup/reference data only -- no
Receipt/Payment Voucher or GL posting reads from a BankAccount or a
CurrencyRate (the whole app is single-currency, SGD, per CLAUDE.md's
approved architecture).

The Bank Book (2026-09-12: "Bank Opening Balances / Bank Transaction
Debit / Credit and Ledger Balances / Bank Reconciliation") is
DELIBERATELY its own ledger, separate from the General Ledger's
Journal Vouchers -- confirmed with Dennis: today, nothing auto-posts
to the GL except manually-entered Journal Vouchers (which account a
Receipt/Payment Voucher should hit is still an open item, #4b.2 in
open-business-decisions.md), so tying a day-to-day Bank Book to that
would mean every bank transaction must first be keyed as a JV. A
BankAccount's `gl_account_id` link to the Chart of Accounts stays as
setup metadata only; this Bank Book does not post to it.
"""
import uuid
from datetime import date, datetime
from decimal import Decimal

from sqlalchemy import (
    Boolean,
    Date,
    DateTime,
    ForeignKey,
    Numeric,
    String,
    Text,
    UniqueConstraint,
    func,
)
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base


class BankAccount(Base):
    """One of the company's own bank accounts -- the Bank Master File."""

    __tablename__ = "bank_accounts"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    bank_name: Mapped[str] = mapped_column(String(150), nullable=False)
    account_name: Mapped[str] = mapped_column(String(150), nullable=False)
    account_number: Mapped[str] = mapped_column(String(50), nullable=False)
    branch: Mapped[str | None] = mapped_column(String(150), nullable=True)
    swift_code: Mapped[str | None] = mapped_column(String(20), nullable=True)
    currency_code: Mapped[str] = mapped_column(String(3), nullable=False, default="SGD")
    # Optional link to the Chart of Accounts entry this account's cash
    # balance is booked under -- nothing posts to it automatically yet.
    gl_account_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("accounts.id"), nullable=True)
    # The Bank Book's starting point (2026-09-12: "Bank Opening
    # Balances") -- the balance as at `opening_balance_date`, before any
    # BankTransaction row. Both null/zero by default: a bank account
    # added with no history simply starts at 0 from day one.
    opening_balance_sgd: Mapped[Decimal] = mapped_column(Numeric(14, 2), nullable=False, default=0)
    opening_balance_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


class BankTransaction(Base):
    """One line of the Bank Book (2026-09-12: "Bank Transaction Debit /
    Credit and Ledger Balances") -- a plain debit/credit entry against
    one BankAccount, independent of the General Ledger's Journal
    Vouchers (see module docstring for why). `debit_sgd` is money IN,
    `credit_sgd` is money OUT -- the same convention as JournalLine,
    for the same reason: a line carries one or the other, never both.

    Never hard-deleted (CLAUDE.md: never permanently delete financial
    records) -- a wrong entry is voided with a reason instead, the same
    pattern as JobOrder.void_reason, and stays visible in the ledger
    marked as voided rather than silently disappearing."""

    __tablename__ = "bank_transactions"

    id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    bank_account_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("bank_accounts.id"), nullable=False)
    transaction_number: Mapped[str] = mapped_column(String(50), nullable=False)
    transaction_date: Mapped[date] = mapped_column(Date, nullable=False)
    description: Mapped[str] = mapped_column(String(500), nullable=False)
    # Cheque number / transfer reference / counterparty -- free text,
    # whatever the bank statement itself shows for matching.
    reference: Mapped[str | None] = mapped_column(String(200), nullable=True)
    # Set when the Bank step (ACC-002) created this line from a Receipt
    # or Payment Voucher -- mirrors JournalEntry.source_type/source_id so
    # a voucher can be banked at most once and the line links back to
    # its document. Hand-keyed lines leave both null.
    source_type: Mapped[str | None] = mapped_column(String(50), nullable=True)
    source_id: Mapped[uuid.UUID | None] = mapped_column(UUID(as_uuid=True), nullable=True)
    debit_sgd: Mapped[Decimal] = mapped_column(Numeric(14, 2), nullable=False, default=0)
    credit_sgd: Mapped[Decimal] = mapped_column(Numeric(14, 2), nullable=False, default=0)
    # Ticked off against a bank statement -- see Bank Reconciliation
    # below. A quick per-line toggle; BankReconciliation is the saved
    # record of a full reconciliation session.
    is_reconciled: Mapped[bool] = mapped_column(Boolean, nullable=False, default=False)
    reconciled_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    is_voided: Mapped[bool] = mapped_column(Boolean, nullable=False, default=False)
    void_reason: Mapped[str | None] = mapped_column(String(500), nullable=True)
    voided_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_by_user_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


class BankReconciliation(Base):
    """One completed Bank Reconciliation session (2026-09-12) -- a
    snapshot of the ledger balance vs. the bank statement's own balance
    as at `statement_date`, plus which transactions were ticked off as
    part of it (see BankTransaction.is_reconciled). Kept as a history
    (never edited/deleted) so "when did we last reconcile, and against
    what statement balance" is always answerable."""

    __tablename__ = "bank_reconciliations"

    id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    bank_account_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("bank_accounts.id"), nullable=False)
    statement_date: Mapped[date] = mapped_column(Date, nullable=False)
    statement_balance_sgd: Mapped[Decimal] = mapped_column(Numeric(14, 2), nullable=False)
    # Snapshots taken at save time -- the Bank Book keeps moving after
    # this, so these two numbers are what reconciled, not a live query.
    ledger_balance_sgd: Mapped[Decimal] = mapped_column(Numeric(14, 2), nullable=False)
    difference_sgd: Mapped[Decimal] = mapped_column(Numeric(14, 2), nullable=False)
    note: Mapped[str | None] = mapped_column(Text, nullable=True)
    reconciled_by_user_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


class CurrencyRate(Base):
    """A currency's rate to the company's base currency (SGD) as at a
    given date -- a rate table only; nothing in the app converts an
    amount using it yet (see module docstring)."""

    __tablename__ = "currency_rates"
    __table_args__ = (
        UniqueConstraint("company_id", "currency_code", "effective_date", name="uq_currency_rate"),
    )

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    currency_code: Mapped[str] = mapped_column(String(3), nullable=False)
    rate_to_base: Mapped[Decimal] = mapped_column(Numeric(18, 6), nullable=False)
    effective_date: Mapped[date] = mapped_column(Date, nullable=False)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
