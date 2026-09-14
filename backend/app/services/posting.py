"""
Sub-ledger -> General Ledger posting (ACC-001..004).

Until 2026-09-14 nothing in the sub-ledger posted to the GL: invoices,
supplier bills, receipts and payments kept their own balances and never
created a JournalEntry, so the Trial Balance was only ever trivially
balanced. This module is the one place that turns an accounting event on
a document into a balanced, posted voucher. See
docs/gl-posting-design.md for the rules and the decisions behind them.

Design points that matter when changing this file:

- The ACCOUNT MAP below is the only place account codes live. A chart
  change is a one-line change here.
- Every posting is linked to its document through JournalEntry.
  source_type / source_id, and a partial unique index guarantees at
  most one *live* (non-reversed) entry per document. `live_entry_for`
  is checked first so the caller gets a clear error, not an IntegrityError.
- Auto-posted vouchers reuse their source document's own number
  (INV-..., BILL-..., RV-..., PV-...) instead of drawing from a counter,
  so the ledger listing reads back to the document and no RV/PV numbers
  are burned by their own GL entries.
- GL posting happens automatically at the accounting event (ACC-003,
  a pragmatic default). The explicit Bank step (ACC-002) and UNGL
  reversal live in the routers that expose them; both build on
  `live_entry_for` here.
- Amounts are SGD only. `BankAccount.currency_code` is stored but not
  consulted (multi-currency is open item 4b.5).
"""
from __future__ import annotations

import uuid
from datetime import date
from decimal import Decimal

from sqlalchemy.orm import Session

from datetime import datetime, timezone

from app.models.accounting import Account, JournalEntry, JournalStatus, VoucherType
from app.models.billing import Invoice, InvoiceType
from app.models.payables import SupplierInvoice, SupplierPayment
from app.models.payments import Payment
from app.models.periods import PeriodDocType, PeriodOperation
from app.models.treasury import BankAccount, BankTransaction
from app.services import audit
from app.services import ledger as ledger_svc
from app.services.numbering import next_document_number
from app.services.periods import (
    PeriodClosedError,
    PeriodLockedError,
    require_period_allows,
    voucher_type_to_doc_type,
)


class PostingError(Exception):
    """A document could not be posted -- surfaced as a 422, not a crash."""


# ── Account map (docs/gl-posting-design.md §3) ────────────────────────────
AR_CONTROL = "1100"          # Accounts receivable
AP_CONTROL = "2000"          # Accounts payable
GST_OUTPUT = "2100"          # GST output tax (collected on sales)
GST_INPUT = "2110"           # GST input tax (paid on purchases)
CASH_AT_BANK_FALLBACK = "1000"  # when a BankAccount has no gl_account_id
EXPENSE_DEFAULT = "5000"     # Cost of services -- bills with no expense account

# Only two invoice types issue today. 4020 Project revenue and 4030
# Hardware sales exist in the chart and are mapped so they work the day
# an invoice type is added for them.
REVENUE_BY_INVOICE_TYPE: dict[InvoiceType, str] = {
    InvoiceType.CONTRACT_ANNUAL: "4000",
    InvoiceType.EXCESS_USAGE: "4010",
}

# source_type values -- shared with the Bank step and UNGL endpoints.
SOURCE_INVOICE = "invoice"
SOURCE_SUPPLIER_INVOICE = "supplier_invoice"
SOURCE_RECEIPT = "payment"            # the receipt model is `Payment`
SOURCE_SUPPLIER_PAYMENT = "supplier_payment"


# ── Lookups ───────────────────────────────────────────────────────────────
def account_by_code(db: Session, company_id: uuid.UUID, code: str) -> Account:
    acct = (
        db.query(Account)
        .filter(Account.company_id == company_id, Account.code == code)
        .first()
    )
    if acct is None:
        raise PostingError(
            f"GL account {code} is missing from this company's Chart of Accounts, "
            "so this document cannot be posted. Add it under Chart of Accounts."
        )
    if not acct.is_active:
        raise PostingError(
            f"GL account {code} {acct.name} is retired and cannot be posted to."
        )
    return acct


def bank_gl_account(db: Session, company_id: uuid.UUID, bank_account_id: uuid.UUID | None) -> Account:
    """The GL account behind a bank account: its own gl_account_id, else
    1000 Cash at bank."""
    if bank_account_id is None:
        raise PostingError("A bank account is required before this voucher can be posted.")
    bank = db.get(BankAccount, bank_account_id)
    if bank is None or bank.company_id != company_id:
        raise PostingError("Unknown bank account on this voucher.")
    if bank.gl_account_id is not None:
        acct = db.get(Account, bank.gl_account_id)
        if acct is not None and acct.is_active:
            return acct
    return account_by_code(db, company_id, CASH_AT_BANK_FALLBACK)


def live_entry_for(db: Session, source_type: str, source_id: uuid.UUID) -> JournalEntry | None:
    """The one non-reversed entry posted for a document, if any."""
    return (
        db.query(JournalEntry)
        .filter(
            JournalEntry.source_type == source_type,
            JournalEntry.source_id == source_id,
            JournalEntry.status != JournalStatus.REVERSED,
        )
        .first()
    )


# ── Core ──────────────────────────────────────────────────────────────────
def _post(
    db: Session,
    *,
    company_id: uuid.UUID,
    voucher_type: VoucherType,
    voucher_number: str,
    entry_date: date,
    narration: str,
    lines: list[dict],
    source_type: str,
    source_id: uuid.UUID,
    actor_user_id: uuid.UUID | None,
    audit_entity_type: str,
) -> JournalEntry:
    if live_entry_for(db, source_type, source_id) is not None:
        raise PostingError(f"{voucher_number} is already posted to the GL.")

    try:
        entry = ledger_svc.create_journal_entry(
            db,
            company_id=company_id,
            entry_date=entry_date,
            narration=narration,
            lines=lines,
            voucher_type=voucher_type,
            voucher_number=voucher_number,
            created_by_user_id=actor_user_id,
            source_type=source_type,
            source_id=source_id,
        )
        ledger_svc.post_entry(db, entry, actor_user_id=actor_user_id)
    except ledger_svc.LedgerRuleViolation as e:
        raise PostingError(str(e)) from e

    audit.record(
        db,
        entity_type=audit_entity_type,
        entity_id=source_id,
        action="gl_posted",
        actor_user_id=actor_user_id,
        details=f"{voucher_number} posted to GL",
        new_value={
            "journal_entry_id": str(entry.id),
            "voucher_number": entry.voucher_number,
            "entry_date": entry_date.isoformat(),
            "lines": [
                {"account": ln["account_code"], "debit": str(ln.get("debit_sgd") or 0), "credit": str(ln.get("credit_sgd") or 0)}
                for ln in lines
            ],
        },
    )
    return entry


def _line(account: Account, *, debit: Decimal = Decimal("0"), credit: Decimal = Decimal("0"), description: str | None = None) -> dict:
    return {
        "account_id": account.id,
        "account_code": account.code,  # for the audit trail only; ignored by the ledger
        "debit_sgd": debit,
        "credit_sgd": credit,
        "description": description,
    }


# ── 4.1 Sales Invoice -- on issue ─────────────────────────────────────────
def post_invoice(db: Session, invoice: Invoice, *, actor_user_id: uuid.UUID | None) -> JournalEntry:
    """Dr 1100 AR total / Cr revenue net / Cr 2100 GST output."""
    cid = invoice.company_id
    revenue_code = REVENUE_BY_INVOICE_TYPE.get(invoice.invoice_type)
    if revenue_code is None:
        raise PostingError(
            f"No revenue account is mapped for invoice type '{invoice.invoice_type.value}'."
        )
    ar = account_by_code(db, cid, AR_CONTROL)
    revenue = account_by_code(db, cid, revenue_code)
    total = Decimal(invoice.total_amount_sgd)
    net = Decimal(invoice.amount_sgd)
    gst = Decimal(invoice.gst_amount_sgd or 0)

    customer_name = invoice.customer.name if getattr(invoice, "customer", None) else ""
    lines = [
        _line(ar, debit=total, description=f"{invoice.invoice_number} {customer_name}".strip()),
        _line(revenue, credit=net, description=invoice.description),
    ]
    if gst > 0:
        lines.append(_line(account_by_code(db, cid, GST_OUTPUT), credit=gst, description=f"GST {invoice.tax_code}"))

    entry_date = invoice.issued_at.date() if invoice.issued_at else date.today()
    return _post(
        db,
        company_id=cid,
        voucher_type=VoucherType.SALES_INVOICE,
        voucher_number=invoice.invoice_number,
        entry_date=entry_date,
        narration=f"Sales invoice {invoice.invoice_number} — {customer_name}".strip(" —"),
        lines=lines,
        source_type=SOURCE_INVOICE,
        source_id=invoice.id,
        actor_user_id=actor_user_id,
        audit_entity_type="invoice",
    )


# ── 4.2 Supplier Bill -- on reaching approved ─────────────────────────────
def post_supplier_invoice(db: Session, bill: SupplierInvoice, *, actor_user_id: uuid.UUID | None) -> JournalEntry:
    """Dr expense net / Dr 2110 GST input / Cr 2000 AP total."""
    cid = bill.company_id
    if bill.expense_account_id is not None:
        expense = db.get(Account, bill.expense_account_id)
        if expense is None or expense.company_id != cid or not expense.is_active:
            raise PostingError("The expense account on this bill is missing or retired.")
    else:
        expense = account_by_code(db, cid, EXPENSE_DEFAULT)
    ap = account_by_code(db, cid, AP_CONTROL)
    net = Decimal(bill.amount_sgd)
    gst = Decimal(bill.gst_amount_sgd or 0)
    total = Decimal(bill.total_amount_sgd)

    supplier_name = bill.supplier.name if getattr(bill, "supplier", None) else ""
    lines = [_line(expense, debit=net, description=bill.description)]
    if gst > 0:
        lines.append(_line(account_by_code(db, cid, GST_INPUT), debit=gst, description="GST input"))
    lines.append(_line(ap, credit=total, description=f"{bill.bill_number} {supplier_name}".strip()))

    return _post(
        db,
        company_id=cid,
        voucher_type=VoucherType.PURCHASE_INVOICE,
        voucher_number=bill.bill_number,
        entry_date=bill.invoice_date,
        narration=f"Supplier bill {bill.bill_number} — {supplier_name}".strip(" —"),
        lines=lines,
        source_type=SOURCE_SUPPLIER_INVOICE,
        source_id=bill.id,
        actor_user_id=actor_user_id,
        audit_entity_type="supplier_invoice",
    )


# ── 4.3 Receipt Voucher -- on save ────────────────────────────────────────
def post_receipt(db: Session, payment: Payment, *, actor_user_id: uuid.UUID | None) -> JournalEntry:
    """Dr bank GL / Cr 1100 AR."""
    cid = payment.company_id
    bank = bank_gl_account(db, cid, payment.bank_account_id)
    ar = account_by_code(db, cid, AR_CONTROL)
    amount = Decimal(payment.amount_sgd)
    customer_name = payment.customer.name if getattr(payment, "customer", None) else ""
    ref = f" ref {payment.reference}" if payment.reference else ""

    return _post(
        db,
        company_id=cid,
        voucher_type=VoucherType.RECEIPT,
        voucher_number=payment.voucher_number,
        entry_date=payment.payment_date,
        narration=f"Receipt {payment.voucher_number} — {customer_name}{ref}".strip(" —"),
        lines=[
            _line(bank, debit=amount, description=f"{payment.voucher_number}{ref}"),
            _line(ar, credit=amount, description=customer_name or None),
        ],
        source_type=SOURCE_RECEIPT,
        source_id=payment.id,
        actor_user_id=actor_user_id,
        audit_entity_type="payment",
    )


# ── 4.4 Payment Voucher -- on save ────────────────────────────────────────
def post_supplier_payment(db: Session, payment: SupplierPayment, *, actor_user_id: uuid.UUID | None) -> JournalEntry:
    """Dr 2000 AP / Cr bank GL."""
    cid = payment.company_id
    bank = bank_gl_account(db, cid, payment.bank_account_id)
    ap = account_by_code(db, cid, AP_CONTROL)
    amount = Decimal(payment.amount_sgd)
    supplier_name = payment.supplier.name if getattr(payment, "supplier", None) else ""
    ref = f" ref {payment.reference}" if payment.reference else ""

    return _post(
        db,
        company_id=cid,
        voucher_type=VoucherType.PAYMENT,
        voucher_number=payment.voucher_number,
        entry_date=payment.payment_date,
        narration=f"Payment {payment.voucher_number} — {supplier_name}{ref}".strip(" —"),
        lines=[
            _line(ap, debit=amount, description=supplier_name or None),
            _line(bank, credit=amount, description=f"{payment.voucher_number}{ref}"),
        ],
        source_type=SOURCE_SUPPLIER_PAYMENT,
        source_id=payment.id,
        actor_user_id=actor_user_id,
        audit_entity_type="supplier_payment",
    )


# ── UNGL (ACC-004, §4.6) ──────────────────────────────────────────────────
def _guard(db: Session, company_id: uuid.UUID, on: date, doc_type: PeriodDocType, op: PeriodOperation) -> None:
    try:
        require_period_allows(db, company_id, on, doc_type, op)
    except (PeriodLockedError, PeriodClosedError) as e:
        raise PostingError(str(e)) from e


def unpost(
    db: Session,
    *,
    source_type: str,
    source_id: uuid.UUID,
    actor_user_id: uuid.UUID,
    reason: str,
    audit_entity_type: str,
) -> JournalEntry:
    """Reverse a document's live GL entry. Nothing is deleted: the original
    is marked reversed and a mirror-image voucher is posted (ACC-004).
    Guarded by UNGL on the original entry's date; `reverse_entry` also
    applies the REVERSE guard, so both locks must be open."""
    entry = live_entry_for(db, source_type, source_id)
    if entry is None:
        raise PostingError("This document has no live GL posting to reverse.")
    _guard(db, entry.company_id, entry.entry_date, voucher_type_to_doc_type(entry.voucher_type), PeriodOperation.UNGL)

    # Label the reversal from its source ("RV-2026-0003-REV", "-REV2" if
    # the document was reversed and re-posted before) instead of drawing
    # a number from the document's own counter.
    prior = (
        db.query(JournalEntry)
        .filter(JournalEntry.company_id == entry.company_id, JournalEntry.voucher_number.like(f"{entry.voucher_number}-REV%"))
        .count()
    )
    label = f"{entry.voucher_number}-REV" + (str(prior + 1) if prior else "")
    try:
        reversal = ledger_svc.reverse_entry(
            db, entry, actor_user_id=actor_user_id, reason=reason, voucher_number=label
        )
    except ledger_svc.LedgerRuleViolation as e:
        raise PostingError(str(e)) from e
    db.flush()  # make the original's REVERSED status durable before anyone queries
    audit.record(
        db,
        entity_type=audit_entity_type,
        entity_id=source_id,
        action="gl_unposted",
        actor_user_id=actor_user_id,
        reason=reason,
        details=f"{entry.voucher_number} reversed by {reversal.voucher_number}",
        new_value={"reversed_entry_id": str(entry.id), "reversal_entry_id": str(reversal.id)},
    )
    return reversal


# ── Bank step (ACC-002, §4.5) -- receipts and payments only ───────────────
def live_bank_transaction_for(db: Session, source_type: str, source_id: uuid.UUID) -> BankTransaction | None:
    return (
        db.query(BankTransaction)
        .filter(
            BankTransaction.source_type == source_type,
            BankTransaction.source_id == source_id,
            BankTransaction.is_voided.is_(False),
        )
        .first()
    )


def _bank(
    db: Session,
    *,
    company_id: uuid.UUID,
    bank_account_id: uuid.UUID | None,
    source_type: str,
    source_id: uuid.UUID,
    voucher_number: str,
    on: date,
    amount: Decimal,
    money_in: bool,
    description: str,
    reference: str | None,
    doc_type: PeriodDocType,
    actor_user_id: uuid.UUID,
    audit_entity_type: str,
) -> BankTransaction:
    if bank_account_id is None:
        raise PostingError("Set a bank account on this voucher before banking it.")
    bank = db.get(BankAccount, bank_account_id)
    if bank is None or bank.company_id != company_id:
        raise PostingError("Unknown bank account on this voucher.")
    if live_bank_transaction_for(db, source_type, source_id) is not None:
        raise PostingError(f"{voucher_number} is already in the bank book.")
    _guard(db, company_id, on, doc_type, PeriodOperation.BANK)

    txn = BankTransaction(
        company_id=company_id,
        bank_account_id=bank.id,
        transaction_number=next_document_number(db, company_id=company_id, doc_kind="bank_transaction"),
        transaction_date=on,
        description=description,
        reference=reference,
        debit_sgd=amount if money_in else Decimal("0"),
        credit_sgd=Decimal("0") if money_in else amount,
        source_type=source_type,
        source_id=source_id,
        created_by_user_id=actor_user_id,
    )
    db.add(txn)
    db.flush()
    audit.record(
        db,
        entity_type=audit_entity_type,
        entity_id=source_id,
        action="banked",
        actor_user_id=actor_user_id,
        details=f"{voucher_number} entered in bank book as {txn.transaction_number} ({bank.bank_name})",
        new_value={"bank_transaction_id": str(txn.id), "transaction_number": txn.transaction_number, "amount_sgd": str(amount), "direction": "in" if money_in else "out"},
    )
    return txn


def bank_receipt(db: Session, payment: Payment, *, actor_user_id: uuid.UUID) -> BankTransaction:
    customer_name = payment.customer.name if getattr(payment, "customer", None) else ""
    return _bank(
        db,
        company_id=payment.company_id,
        bank_account_id=payment.bank_account_id,
        source_type=SOURCE_RECEIPT,
        source_id=payment.id,
        voucher_number=payment.voucher_number,
        on=payment.payment_date,
        amount=Decimal(payment.amount_sgd),
        money_in=True,
        description=f"{payment.voucher_number} — {customer_name}".strip(" —"),
        reference=payment.reference,
        doc_type=PeriodDocType.RECEIPT_VOUCHER,
        actor_user_id=actor_user_id,
        audit_entity_type="payment",
    )


def bank_supplier_payment(db: Session, payment: SupplierPayment, *, actor_user_id: uuid.UUID) -> BankTransaction:
    supplier_name = payment.supplier.name if getattr(payment, "supplier", None) else ""
    return _bank(
        db,
        company_id=payment.company_id,
        bank_account_id=payment.bank_account_id,
        source_type=SOURCE_SUPPLIER_PAYMENT,
        source_id=payment.id,
        voucher_number=payment.voucher_number,
        on=payment.payment_date,
        amount=Decimal(payment.amount_sgd),
        money_in=False,
        description=f"{payment.voucher_number} — {supplier_name}".strip(" —"),
        reference=payment.reference,
        doc_type=PeriodDocType.PAYMENT_VOUCHER,
        actor_user_id=actor_user_id,
        audit_entity_type="supplier_payment",
    )


def unbank(
    db: Session,
    *,
    source_type: str,
    source_id: uuid.UUID,
    doc_type: PeriodDocType,
    actor_user_id: uuid.UUID,
    reason: str,
    audit_entity_type: str,
) -> BankTransaction:
    """Void the bank-book line the Bank step created (ACC-004: void, never
    delete). A reconciled line cannot be unbanked -- undo the
    reconciliation first."""
    if not reason.strip():
        raise PostingError("A reason is required to unbank a voucher.")
    txn = live_bank_transaction_for(db, source_type, source_id)
    if txn is None:
        raise PostingError("This voucher is not in the bank book.")
    if txn.is_reconciled:
        raise PostingError(
            f"{txn.transaction_number} has been reconciled to a bank statement; "
            "undo the reconciliation before unbanking it."
        )
    _guard(db, txn.company_id, txn.transaction_date, doc_type, PeriodOperation.UNBANK)

    txn.is_voided = True
    txn.void_reason = reason
    txn.voided_at = datetime.now(timezone.utc)
    db.flush()
    audit.record(
        db,
        entity_type=audit_entity_type,
        entity_id=source_id,
        action="unbanked",
        actor_user_id=actor_user_id,
        reason=reason,
        details=f"bank book line {txn.transaction_number} voided",
        new_value={"bank_transaction_id": str(txn.id), "voided": True},
    )
    return txn


# ── Status for list screens (§7) ──────────────────────────────────────────
# One query per document type per request, not one per row.
def gl_status_map(db: Session, company_id: uuid.UUID, source_type: str) -> dict[uuid.UUID, dict]:
    """source_id -> {"gl_status": posted|reversed|not_posted, "gl_voucher_number"}.
    A document with a live entry is "posted"; one whose only entries are
    reversed is "reversed"; otherwise absent (caller treats as not_posted)."""
    rows = (
        db.query(JournalEntry.source_id, JournalEntry.status, JournalEntry.voucher_number)
        .filter(JournalEntry.company_id == company_id, JournalEntry.source_type == source_type)
        .order_by(JournalEntry.created_at)
        .all()
    )
    out: dict[uuid.UUID, dict] = {}
    for source_id, status, number in rows:
        if status != JournalStatus.REVERSED:
            out[source_id] = {"gl_status": "posted", "gl_voucher_number": number}
        elif source_id not in out:
            out[source_id] = {"gl_status": "reversed", "gl_voucher_number": number}
    return out


def bank_status_map(db: Session, company_id: uuid.UUID, source_type: str) -> dict[uuid.UUID, dict]:
    """source_id -> {"bank_status": "banked", "bank_transaction_number"} for
    vouchers with a live (non-voided) bank-book line."""
    rows = (
        db.query(BankTransaction.source_id, BankTransaction.transaction_number)
        .filter(
            BankTransaction.company_id == company_id,
            BankTransaction.source_type == source_type,
            BankTransaction.is_voided.is_(False),
        )
        .all()
    )
    return {sid: {"bank_status": "banked", "bank_transaction_number": num} for sid, num in rows}


def decorate(db: Session, company_id: uuid.UUID, outs: list, source_type: str, *, with_bank: bool = False) -> list:
    """Stamp gl_* (and bank_* for RV/PV) onto already-built Out models."""
    if not outs:
        return outs
    gl = gl_status_map(db, company_id, source_type)
    bank = bank_status_map(db, company_id, source_type) if with_bank else {}
    for o in outs:
        for k, v in gl.get(o.id, {}).items():
            setattr(o, k, v)
        for k, v in bank.get(o.id, {}).items():
            setattr(o, k, v)
    return outs
