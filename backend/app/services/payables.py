"""
Accounts Payable business logic.

- PUR-001: a purchase order below the company's approval threshold is
  approved by procurement/finance; above it, the owner approves. The
  threshold is undecided (open item 4.4), so while it is unset EVERY
  purchase order goes to the owner.
- PUR-002: 2-way matching. A supplier invoice is compared to its
  purchase order and nothing else -- there is no goods receipt.
- PUR-003: a bill that matches its PO is auto-approved for payment.
  A mismatch is recorded as an EXCEPTION with the discrepancy spelled
  out, and stops there: how mismatches get resolved is open item 4.5,
  so this deliberately does not decide it.
"""
import uuid
from datetime import timedelta
from decimal import Decimal

from sqlalchemy.orm import Session

from app.models.core import Company, User, UserRole
from app.models.company_individuals import CompanyIndividual
from app.services import posting
from app.models.payables import (
    BillMatchStatus,
    BillStatus,
    PurchaseOrder,
    PurchaseOrderStatus,
    SupplierInvoice,
    SupplierPayment,
    SupplierPaymentAllocation,
)


class PayablesRuleViolation(Exception):
    """An AP rule was broken -- surfaced as a 422."""


def po_needs_owner_approval(db: Session, company_id: uuid.UUID, amount: Decimal) -> bool:
    """PUR-001. With no threshold set (open item 4.4), everything needs
    the owner -- the safe reading of an undecided rule."""
    company = db.get(Company, company_id)
    threshold = company.po_approval_threshold_sgd if company else None
    if threshold is None:
        return True
    return Decimal(amount) > Decimal(threshold)


def approve_purchase_order(
    db: Session, po: PurchaseOrder, *, actor: User
) -> PurchaseOrder:
    """PUR-001: approve a PO, respecting the value threshold."""
    from datetime import datetime, timezone

    if po.status == PurchaseOrderStatus.APPROVED:
        raise PayablesRuleViolation("That purchase order is already approved.")
    if po.status == PurchaseOrderStatus.CANCELLED:
        raise PayablesRuleViolation("That purchase order was cancelled.")

    if po_needs_owner_approval(db, po.company_id, po.total_amount_sgd):
        if actor.role != UserRole.OWNER:
            company = db.get(Company, po.company_id)
            threshold = company.po_approval_threshold_sgd if company else None
            if threshold is None:
                raise PayablesRuleViolation(
                    "No purchase order approval threshold has been set, so every PO needs "
                    "the owner's approval (PUR-001). Set a threshold in Company Setup, or "
                    "ask the owner to approve this."
                )
            raise PayablesRuleViolation(
                f"SGD {po.total_amount_sgd} is above the SGD {threshold} approval "
                "threshold -- the owner must approve this purchase order (PUR-001)."
            )

    po.status = PurchaseOrderStatus.APPROVED
    po.approved_by_user_id = actor.id
    po.approved_at = datetime.now(timezone.utc)
    return po


def assert_po_importable_to_ap(po: PurchaseOrder) -> None:
    """Guard for "confirm and import to AP" (2026-09-12): a PO must be
    confirmed (PUR-001 approved) before it becomes a bill, and each PO
    can only be imported once -- re-importing would double the AP
    liability for the same spend."""
    if po.status != PurchaseOrderStatus.APPROVED:
        raise PayablesRuleViolation(
            f"{po.po_number} is {po.status.value.replace('_', ' ')}, not approved -- "
            "approve it first (PUR-001) before importing it to Accounts Payable."
        )
    if po.bills:
        raise PayablesRuleViolation(
            f"{po.po_number} was already imported to Accounts Payable as "
            f"{po.bills[0].bill_number}."
        )


def match_bill_to_po(
    db: Session, bill: SupplierInvoice, *, actor_user_id: uuid.UUID | None = None
) -> SupplierInvoice:
    """PUR-002 2-way match, and PUR-003's consequence.

    Compares the bill to its purchase order. Agreement auto-approves it
    for payment; disagreement records exactly what differs and leaves it
    as an exception for a human -- resolution is open item 4.5.
    """
    if bill.purchase_order_id is None:
        # No PO to match against. Not an error -- some spend legitimately
        # has no purchase order -- but it cannot be auto-approved under
        # PUR-003 either, so it waits for a person.
        bill.match_status = BillMatchStatus.NOT_MATCHED
        bill.match_note = "No purchase order referenced, so 2-way matching does not apply."
        bill.status = BillStatus.AWAITING_MATCH
        return bill

    po = db.get(PurchaseOrder, bill.purchase_order_id)
    if po is None or po.company_id != bill.company_id:
        raise PayablesRuleViolation("The referenced purchase order does not exist.")

    problems = []
    if po.supplier_id != bill.supplier_id:
        problems.append("the bill is from a different supplier than the purchase order")
    if Decimal(po.total_amount_sgd) != Decimal(bill.total_amount_sgd):
        problems.append(
            f"PO total is SGD {po.total_amount_sgd} but the bill is SGD {bill.total_amount_sgd}"
        )
    if po.status != PurchaseOrderStatus.APPROVED:
        problems.append(f"the purchase order is {po.status.value}, not approved")

    if problems:
        bill.match_status = BillMatchStatus.EXCEPTION
        bill.match_note = "; ".join(problems).capitalize() + "."
        bill.status = BillStatus.EXCEPTION
        return bill

    # PUR-002 satisfied -> PUR-003 auto-approves it for payment.
    bill.match_status = BillMatchStatus.MATCHED
    bill.match_note = f"Matched to {po.po_number} (2-way, PUR-002); auto-approved (PUR-003)."
    bill.status = BillStatus.APPROVED

    # ACC-001/003: reaching `approved` is the bill's accounting event --
    # post it now (Dr expense / Dr GST input / Cr AP). A bill still in
    # awaiting_match or exception never gets here, so an unresolved
    # mismatch never sits in AP (gl-posting-design.md §4.2).
    db.flush()
    try:
        posting.post_supplier_invoice(db, bill, actor_user_id=actor_user_id)
    except posting.PostingError as e:
        raise PayablesRuleViolation(str(e)) from e
    return bill


def due_date_for_bill(db: Session, supplier_id: uuid.UUID, invoice_date):
    """From the supplier's agreed terms. None when none are agreed --
    same treatment customers get."""
    supplier = db.get(CompanyIndividual, supplier_id)
    if supplier is None or supplier.payment_terms_days is None:
        return None
    return invoice_date + timedelta(days=supplier.payment_terms_days)


def recalculate_bill_status(db: Session, bill: SupplierInvoice) -> None:
    """Derive a bill's paid state from what is allocated to it, so it
    can't drift out of step with the money actually paid."""
    allocations = (
        db.query(SupplierPaymentAllocation)
        .filter(SupplierPaymentAllocation.supplier_invoice_id == bill.id)
        .all()
    )
    paid = sum((Decimal(a.amount_sgd) for a in allocations), start=Decimal("0.00"))
    bill.amount_paid_sgd = paid

    if bill.status == BillStatus.EXCEPTION and paid <= 0:
        return  # an unresolved exception stays an exception

    total = Decimal(bill.total_amount_sgd)
    if paid <= Decimal("0.00"):
        bill.status = (
            BillStatus.APPROVED
            if bill.match_status == BillMatchStatus.MATCHED
            else BillStatus.AWAITING_MATCH
        )
    elif paid >= total:
        bill.status = BillStatus.PAID
    else:
        bill.status = BillStatus.PARTIALLY_PAID


def allocate_supplier_payment(
    db: Session, payment: SupplierPayment, bill: SupplierInvoice, amount: Decimal
) -> SupplierPaymentAllocation:
    """Apply part of a payment voucher to one bill."""
    amount = Decimal(amount)
    if amount <= Decimal("0.00"):
        raise PayablesRuleViolation("Allocation amount must be greater than zero.")
    if bill.company_id != payment.company_id:
        raise PayablesRuleViolation("Bill and payment belong to different companies.")
    if bill.supplier_id != payment.supplier_id:
        raise PayablesRuleViolation(
            "That bill belongs to a different supplier than this payment."
        )
    if bill.status == BillStatus.EXCEPTION:
        raise PayablesRuleViolation(
            f"{bill.bill_number} is a matching exception and is not approved for payment "
            "(PUR-003). Resolve the mismatch first."
        )
    if amount > payment.unallocated_sgd:
        raise PayablesRuleViolation(
            f"Only SGD {payment.unallocated_sgd} of this payment is still unallocated."
        )
    if amount > bill.outstanding_sgd:
        raise PayablesRuleViolation(
            f"Bill {bill.bill_number} only has SGD {bill.outstanding_sgd} outstanding."
        )

    allocation = SupplierPaymentAllocation(
        company_id=payment.company_id,
        payment_id=payment.id,
        supplier_invoice_id=bill.id,
        amount_sgd=amount,
    )
    db.add(allocation)
    db.flush()
    recalculate_bill_status(db, bill)
    return allocation
