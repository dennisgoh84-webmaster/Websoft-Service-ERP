"""
General ledger posting.

The rule this module exists to enforce: **a voucher may not be posted
unless its debits equal its credits**, and once posted it is immutable.
A mistake is corrected by reversing the voucher (an equal and opposite
entry), never by editing or deleting it, so the ledger keeps the full
history of what happened and what corrected it.

Note on scope: which account a *sales invoice* or *receipt* should post
to is still undecided (open item 4b.2), so nothing posts automatically
yet. The Journal Voucher is the mechanism available today, and there the
accounts are chosen by the person raising it -- no rule is assumed on
Dennis's behalf.
"""
import uuid
from datetime import date
from decimal import Decimal

from sqlalchemy.orm import Session

from app.models.accounting import (
    Account,
    JournalEntry,
    JournalLine,
    JournalStatus,
    VoucherType,
)
from app.services.numbering import next_document_number
from app.models.periods import PeriodOperation
from app.services.periods import (
    PeriodClosedError,
    PeriodLockedError,
    require_period_allows,
    voucher_type_to_doc_type,
)


class LedgerRuleViolation(Exception):
    """A ledger rule was broken -- surfaced as a 422, not a crash."""


def create_journal_entry(
    db: Session,
    *,
    company_id: uuid.UUID,
    entry_date: date,
    narration: str,
    lines: list[dict],
    voucher_type: VoucherType = VoucherType.JOURNAL,
    created_by_user_id: uuid.UUID | None = None,
    source_type: str | None = None,
    source_id: uuid.UUID | None = None,
) -> JournalEntry:
    """Create a DRAFT voucher. `lines` are dicts of account_id, debit_sgd,
    credit_sgd and an optional description."""
    if not lines:
        raise LedgerRuleViolation("A voucher needs at least one line.")

    doc_kind = {
        VoucherType.JOURNAL: "journal",
        VoucherType.RECEIPT: "receipt",
        VoucherType.PAYMENT: "payment",
    }.get(voucher_type, "journal")

    entry = JournalEntry(
        company_id=company_id,
        voucher_number=next_document_number(db, company_id=company_id, doc_kind=doc_kind),
        voucher_type=voucher_type,
        entry_date=entry_date,
        narration=narration,
        status=JournalStatus.DRAFT,
        source_type=source_type,
        source_id=source_id,
        created_by_user_id=created_by_user_id,
    )
    db.add(entry)
    db.flush()

    for raw in lines:
        debit = Decimal(str(raw.get("debit_sgd") or 0))
        credit = Decimal(str(raw.get("credit_sgd") or 0))
        if debit < 0 or credit < 0:
            raise LedgerRuleViolation("Debit and credit amounts cannot be negative.")
        if debit > 0 and credit > 0:
            raise LedgerRuleViolation(
                "A line is either a debit or a credit, not both."
            )
        if debit == 0 and credit == 0:
            raise LedgerRuleViolation("Every line needs a debit or a credit amount.")

        account = db.get(Account, raw["account_id"])
        if account is None or account.company_id != company_id:
            raise LedgerRuleViolation("Unknown account on one of the lines.")
        if not account.is_active:
            raise LedgerRuleViolation(
                f"Account {account.code} {account.name} is retired and cannot be posted to."
            )

        db.add(
            JournalLine(
                entry_id=entry.id,
                account_id=account.id,
                debit_sgd=debit,
                credit_sgd=credit,
                description=raw.get("description"),
            )
        )

    db.flush()
    db.refresh(entry)
    return entry


def post_entry(
    db: Session, entry: JournalEntry, *, actor_user_id: uuid.UUID, bypass_period_check: bool = False
) -> JournalEntry:
    """Post a draft voucher to the ledger. Refuses to post anything that
    doesn't balance -- that check is the whole point of double entry --
    or anything dated inside a closed accounting period (see
    app/services/periods.py). `bypass_period_check` exists only for the
    Year-End Closing voucher itself, which is deliberately dated inside
    a period that is closed by definition."""
    from datetime import datetime, timezone

    if entry.status == JournalStatus.POSTED:
        raise LedgerRuleViolation("That voucher is already posted.")
    if entry.status == JournalStatus.REVERSED:
        raise LedgerRuleViolation("That voucher has been reversed.")

    if not bypass_period_check:
        try:
            doc_type = voucher_type_to_doc_type(entry.voucher_type)
            require_period_allows(db, entry.company_id, entry.entry_date, doc_type, PeriodOperation.GL)
        except (PeriodLockedError, PeriodClosedError) as e:
            raise LedgerRuleViolation(str(e))

    if entry.total_debit != entry.total_credit:
        raise LedgerRuleViolation(
            f"Voucher does not balance: debits SGD {entry.total_debit} vs "
            f"credits SGD {entry.total_credit}."
        )
    if entry.total_debit <= Decimal("0.00"):
        raise LedgerRuleViolation("A voucher must move a non-zero amount.")

    entry.status = JournalStatus.POSTED
    entry.posted_by_user_id = actor_user_id
    entry.posted_at = datetime.now(timezone.utc)
    return entry


def reverse_entry(
    db: Session, entry: JournalEntry, *, actor_user_id: uuid.UUID, reason: str
) -> JournalEntry:
    """Reverse a posted voucher by writing its mirror image.

    The original is left exactly as it was and marked reversed; the new
    entry carries the opposite debits and credits. Nothing is edited or
    deleted, so both the mistake and its correction stay on record.
    """
    if entry.status != JournalStatus.POSTED:
        raise LedgerRuleViolation("Only a posted voucher can be reversed.")
    if not reason.strip():
        raise LedgerRuleViolation("A reason is required to reverse a voucher.")

    # Check REVERSE lock on the original entry's period
    try:
        doc_type = voucher_type_to_doc_type(entry.voucher_type)
        require_period_allows(db, entry.company_id, entry.entry_date, doc_type, PeriodOperation.REVERSE)
    except (PeriodLockedError, PeriodClosedError) as e:
        raise LedgerRuleViolation(str(e))

    reversal = create_journal_entry(
        db,
        company_id=entry.company_id,
        entry_date=date.today(),
        narration=f"Reversal of {entry.voucher_number}: {reason}",
        voucher_type=entry.voucher_type,
        created_by_user_id=actor_user_id,
        source_type=entry.source_type,
        source_id=entry.source_id,
        lines=[
            {
                "account_id": line.account_id,
                # Swapped: what was debited is credited back.
                "debit_sgd": line.credit_sgd,
                "credit_sgd": line.debit_sgd,
                "description": line.description,
            }
            for line in entry.lines
        ],
    )
    reversal.reverses_entry_id = entry.id
    post_entry(db, reversal, actor_user_id=actor_user_id)

    entry.status = JournalStatus.REVERSED
    return reversal


def account_transactions(
    db: Session,
    company_id: uuid.UUID,
    account_id: uuid.UUID,
    date_from: date | None = None,
    date_to: date | None = None,
) -> list[dict]:
    """GL transaction ledger for one account: every posted journal line
    touching this account, ordered by date then voucher number, with a
    running balance.

    The running balance is cumulative debit minus credit, starting at
    zero (or at the brought-forward total when ``date_from`` is set).
    """
    account = db.get(Account, account_id)
    if account is None or account.company_id != company_id:
        return []

    # ── Opening balance when a date_from filter is set ──
    opening_balance = Decimal("0.00")
    if date_from:
        bf_query = (
            db.query(JournalLine, JournalEntry)
            .join(JournalEntry, JournalEntry.id == JournalLine.entry_id)
            .filter(
                JournalEntry.company_id == company_id,
                JournalEntry.status == JournalStatus.POSTED,
                JournalLine.account_id == account_id,
                JournalEntry.entry_date < date_from,
            )
        )
        for line, _entry in bf_query.all():
            opening_balance += Decimal(line.debit_sgd) - Decimal(line.credit_sgd)

    # ── Transaction lines in the date window ──
    query = (
        db.query(JournalLine, JournalEntry)
        .join(JournalEntry, JournalEntry.id == JournalLine.entry_id)
        .filter(
            JournalEntry.company_id == company_id,
            JournalEntry.status == JournalStatus.POSTED,
            JournalLine.account_id == account_id,
        )
    )
    if date_from:
        query = query.filter(JournalEntry.entry_date >= date_from)
    if date_to:
        query = query.filter(JournalEntry.entry_date <= date_to)

    query = query.order_by(JournalEntry.entry_date, JournalEntry.voucher_number)

    running = opening_balance
    rows: list[dict] = []
    for line, entry in query.all():
        debit = Decimal(line.debit_sgd)
        credit = Decimal(line.credit_sgd)
        running += debit - credit
        rows.append(
            {
                "line_id": line.id,
                "entry_id": entry.id,
                "voucher_number": entry.voucher_number,
                "voucher_type": entry.voucher_type.value,
                "entry_date": entry.entry_date.isoformat(),
                "narration": entry.narration,
                "line_description": line.description,
                "debit_sgd": float(debit),
                "credit_sgd": float(credit),
                "balance_sgd": float(running),
            }
        )

    return rows


def account_balances(db: Session, company_id: uuid.UUID, as_at: date | None = None) -> list[dict]:
    """Trial balance: every account's posted debits and credits.

    Draft and reversed vouchers are excluded -- only posted entries are
    part of the ledger.
    """
    query = (
        db.query(JournalLine, JournalEntry, Account)
        .join(JournalEntry, JournalEntry.id == JournalLine.entry_id)
        .join(Account, Account.id == JournalLine.account_id)
        .filter(
            JournalEntry.company_id == company_id,
            JournalEntry.status == JournalStatus.POSTED,
        )
    )
    if as_at:
        query = query.filter(JournalEntry.entry_date <= as_at)

    totals: dict[uuid.UUID, dict] = {}
    for line, _entry, account in query.all():
        row = totals.setdefault(
            account.id,
            {
                "account_id": account.id,
                "code": account.code,
                "name": account.name,
                "account_type": account.account_type,
                "debit_sgd": Decimal("0.00"),
                "credit_sgd": Decimal("0.00"),
            },
        )
        row["debit_sgd"] += Decimal(line.debit_sgd)
        row["credit_sgd"] += Decimal(line.credit_sgd)

    rows = sorted(totals.values(), key=lambda r: r["code"])
    for row in rows:
        row["balance_sgd"] = row["debit_sgd"] - row["credit_sgd"]
    return rows
