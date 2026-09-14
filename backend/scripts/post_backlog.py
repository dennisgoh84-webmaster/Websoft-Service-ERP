"""
One-off back-fill: post every existing sub-ledger document to the GL.

Until 2026-09-14 invoices, supplier bills, receipts and payments never
created journal entries (docs/gl-posting-design.md §1). New documents
now post automatically; this script posts the ones that already existed.

    uv run python scripts/post_backlog.py              # all companies
    uv run python scripts/post_backlog.py --dry-run    # report only
    uv run python scripts/post_backlog.py --company-id <uuid>

Safe to re-run: a document with a live posting is skipped (the same
idempotency the posting service enforces). Documents are posted in date
order so the ledger reads chronologically. The Bank step is NOT back-
filled -- finance confirms those against real bank statements by hand.

Runs in one transaction per company: any failure rolls that company
back and is reported; other companies are unaffected.
"""
from __future__ import annotations

import argparse
import sys
import uuid
from datetime import date

from sqlalchemy.orm import Session

# Allow `python scripts/post_backlog.py` from backend/ without installing.
sys.path.insert(0, ".")

from app.core.database import SessionLocal  # noqa: E402
from app.models.billing import Invoice  # noqa: E402
from app.models.core import Company, User, UserRole  # noqa: E402
from app.models.payables import BillStatus, SupplierInvoice, SupplierPayment  # noqa: E402
from app.models.payments import Payment  # noqa: E402
from app.models.treasury import BankAccount  # noqa: E402
from app.services import audit, posting  # noqa: E402

POSTABLE_BILL_STATUSES = (BillStatus.APPROVED, BillStatus.PARTIALLY_PAID, BillStatus.PAID)


def _actor_for(db: Session, company_id: uuid.UUID) -> uuid.UUID | None:
    owner = (
        db.query(User)
        .filter(User.company_id == company_id, User.role == UserRole.OWNER)
        .order_by(User.created_at)
        .first()
    )
    return owner.id if owner else None


def _work_items(db: Session, company_id: uuid.UUID) -> list[tuple[date, str, object]]:
    """(sort date, kind, document) for every document that should be posted."""
    items: list[tuple[date, str, object]] = []
    for inv in db.query(Invoice).filter(Invoice.company_id == company_id):
        items.append(((inv.issued_at.date() if inv.issued_at else date.today()), "invoice", inv))
    for bill in db.query(SupplierInvoice).filter(
        SupplierInvoice.company_id == company_id, SupplierInvoice.status.in_(POSTABLE_BILL_STATUSES)
    ):
        items.append((bill.invoice_date, "supplier_invoice", bill))
    for rv in db.query(Payment).filter(Payment.company_id == company_id):
        items.append((rv.payment_date, "payment", rv))
    for pv in db.query(SupplierPayment).filter(SupplierPayment.company_id == company_id):
        items.append((pv.payment_date, "supplier_payment", pv))
    # Invoices/bills before the money that settles them on the same day.
    order = {"invoice": 0, "supplier_invoice": 1, "payment": 2, "supplier_payment": 3}
    items.sort(key=lambda t: (t[0], order[t[1]]))
    return items


POSTERS = {
    "invoice": (posting.SOURCE_INVOICE, posting.post_invoice),
    "supplier_invoice": (posting.SOURCE_SUPPLIER_INVOICE, posting.post_supplier_invoice),
    "payment": (posting.SOURCE_RECEIPT, posting.post_receipt),
    "supplier_payment": (posting.SOURCE_SUPPLIER_PAYMENT, posting.post_supplier_payment),
}


def run(company_id: uuid.UUID | None, dry_run: bool) -> int:
    audit.set_request_context(ip_address=None, user_agent=None, device_id=None)
    db = SessionLocal()
    failures = 0
    try:
        companies = db.query(Company)
        if company_id:
            companies = companies.filter(Company.id == company_id)
        for company in companies.order_by(Company.name).all():
            actor = _actor_for(db, company.id)
            posted = skipped = errors = 0
            print(f"\n{company.name}")
            for _, kind, doc in _work_items(db, company.id):
                source_type, poster = POSTERS[kind]
                number = getattr(doc, "invoice_number", None) or getattr(doc, "bill_number", None) or getattr(doc, "voucher_number", "?")
                if posting.live_entry_for(db, source_type, doc.id) is not None:
                    skipped += 1
                    continue
                # A receipt/payment from before this version may have no bank
                # account. Apply the same fallback the migration's back-fill
                # used -- the company's oldest active bank account -- and say
                # so, rather than fail the whole company on a NULL we can fill.
                if kind in ("payment", "supplier_payment") and doc.bank_account_id is None:
                    fallback = (
                        db.query(BankAccount)
                        .filter(BankAccount.company_id == company.id, BankAccount.is_active.is_(True))
                        .order_by(BankAccount.created_at)
                        .first()
                    )
                    if fallback is None:
                        print(f"  FAILED     {kind:17} {number}: no bank account on the voucher and the company has none to fall back to")
                        errors += 1
                        continue
                    print(f"  note       {kind:17} {number}: no bank account set; using {fallback.bank_name}")
                    if not dry_run:
                        doc.bank_account_id = fallback.id
                        db.flush()
                if dry_run:
                    print(f"  would post {kind:17} {number}")
                    posted += 1
                    continue
                try:
                    entry = poster(db, doc, actor_user_id=actor)
                    print(f"  posted     {kind:17} {number} -> {entry.voucher_number}")
                    posted += 1
                except posting.PostingError as e:
                    print(f"  FAILED     {kind:17} {number}: {e}")
                    errors += 1
            if errors:
                db.rollback()
                failures += 1
                print(f"  -> {errors} failed; rolled back {company.name}, nothing posted for it.")
            elif dry_run:
                db.rollback()
                print(f"  -> dry run: {posted} to post, {skipped} already posted")
            else:
                db.commit()
                print(f"  -> {posted} posted, {skipped} already posted")
    finally:
        db.close()
    return 1 if failures else 0


if __name__ == "__main__":
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--dry-run", action="store_true", help="report what would be posted; change nothing")
    ap.add_argument("--company-id", type=uuid.UUID, default=None, help="limit to one company")
    args = ap.parse_args()
    sys.exit(run(args.company_id, args.dry_run))
