"""
Commission Management service -- approval, clawback, payout.

Built 2026-09-12, resolving open items 6.3-6.5 in
docs/open-business-decisions.md.
"""
import calendar
import uuid
from datetime import date, datetime
from decimal import Decimal

from sqlalchemy.orm import Session

from app.models.billing import Invoice, InvoiceStatus
from app.models.commissions import (
    CommissionPayout,
    CommissionPayoutStatus,
    CommissionPayoutType,
)
from app.models.contracts import Contract
from app.models.payments import CommissionSettings, PaymentAllocation
from app.services.numbering import next_document_number


class CommissionError(Exception):
    pass


def _commission_rate(db: Session, company_id: uuid.UUID) -> Decimal:
    settings = db.get(CommissionSettings, company_id)
    return Decimal(settings.rate_percent) if settings else Decimal("0.00")


def _month_range(month_str: str) -> tuple[date, date]:
    """Return (first_day, last_day) for a 'YYYY-MM' string."""
    year, month = int(month_str[:4]), int(month_str[5:7])
    last_day = calendar.monthrange(year, month)[1]
    return date(year, month, 1), date(year, month, last_day)


def generate_payouts(
    db: Session,
    company_id: uuid.UUID,
    period_month: str,
    created_by_user_id: uuid.UUID,
) -> list[CommissionPayout]:
    """Generate DRAFT commission payout records for a given month.

    Calculates commission the same way the report does (% of GP prorated
    by receipt allocation), but creates one CommissionPayout per
    salesperson instead of a report row.

    If payouts already exist for this month + company, raises an error
    (don't double-generate — void first if regeneration is needed).
    """
    existing = (
        db.query(CommissionPayout)
        .filter(
            CommissionPayout.company_id == company_id,
            CommissionPayout.period_month == period_month,
            CommissionPayout.payout_type == CommissionPayoutType.EARNING,
            CommissionPayout.status != CommissionPayoutStatus.CANCELLED,
        )
        .first()
    )
    if existing:
        raise CommissionError(
            f"Commission payouts for {period_month} already exist. "
            "Cancel them first if you need to regenerate."
        )

    rate = _commission_rate(db, company_id)
    period_start, period_end = _month_range(period_month)

    # Calculate commission per salesperson for this month — same logic
    # as app/services/reports.py commission_rows but scoped to one month.
    allocations = (
        db.query(PaymentAllocation)
        .join(Invoice, PaymentAllocation.invoice_id == Invoice.id)
        .filter(PaymentAllocation.company_id == company_id)
        .all()
    )

    totals: dict[uuid.UUID | None, Decimal] = {}
    for alloc in allocations:
        invoice = db.get(Invoice, alloc.invoice_id)
        payment = alloc.payment
        if payment is None or not (period_start <= payment.payment_date <= period_end):
            continue
        contract = db.get(Contract, invoice.contract_id) if invoice.contract_id else None
        sales_staff_id = contract.sales_staff_id if contract else None
        if Decimal(invoice.total_amount_sgd) == 0:
            continue
        net_share = (
            Decimal(alloc.amount_sgd)
            * Decimal(invoice.amount_sgd)
            / Decimal(invoice.total_amount_sgd)
        )
        gp_ratio = invoice.gp_percent / Decimal(100)
        commission = (net_share * gp_ratio * rate / Decimal(100)).quantize(Decimal("0.01"))
        totals[sales_staff_id] = totals.get(sales_staff_id, Decimal("0.00")) + commission

    payouts: list[CommissionPayout] = []
    for staff_id, amount in sorted(totals.items(), key=lambda x: str(x[0])):
        if amount == 0:
            continue
        payout = CommissionPayout(
            company_id=company_id,
            payout_number=next_document_number(db, company_id, "CP"),
            payout_type=CommissionPayoutType.EARNING,
            status=CommissionPayoutStatus.DRAFT,
            sales_staff_id=staff_id or created_by_user_id,  # fallback
            period_month=period_month,
            period_start=period_start,
            period_end=period_end,
            amount_sgd=amount,
            rate_percent=rate,
            submitted_by_user_id=created_by_user_id,
        )
        db.add(payout)
        payouts.append(payout)

    db.flush()
    return payouts


def submit_payout(
    db: Session,
    payout_id: uuid.UUID,
    company_id: uuid.UUID,
    user_id: uuid.UUID,
) -> CommissionPayout:
    """Submit a DRAFT payout for approval (6.3)."""
    payout = _get_payout(db, payout_id, company_id)
    if payout.status != CommissionPayoutStatus.DRAFT:
        raise CommissionError(
            f"Only DRAFT payouts can be submitted. Current status: {payout.status.value}"
        )
    payout.status = CommissionPayoutStatus.PENDING_APPROVAL
    payout.submitted_by_user_id = user_id
    payout.submitted_at = datetime.now()
    db.flush()
    return payout


def approve_payout(
    db: Session,
    payout_id: uuid.UUID,
    company_id: uuid.UUID,
    user_id: uuid.UUID,
) -> CommissionPayout:
    """Approve a pending payout (6.3)."""
    payout = _get_payout(db, payout_id, company_id)
    if payout.status != CommissionPayoutStatus.PENDING_APPROVAL:
        raise CommissionError(
            f"Only PENDING_APPROVAL payouts can be approved. Current status: {payout.status.value}"
        )
    payout.status = CommissionPayoutStatus.APPROVED
    payout.approved_by_user_id = user_id
    payout.approved_at = datetime.now()
    db.flush()
    return payout


def reject_payout(
    db: Session,
    payout_id: uuid.UUID,
    company_id: uuid.UUID,
    user_id: uuid.UUID,
    reason: str | None = None,
) -> CommissionPayout:
    """Reject a pending payout back to DRAFT (6.3)."""
    payout = _get_payout(db, payout_id, company_id)
    if payout.status != CommissionPayoutStatus.PENDING_APPROVAL:
        raise CommissionError(
            f"Only PENDING_APPROVAL payouts can be rejected. Current status: {payout.status.value}"
        )
    payout.status = CommissionPayoutStatus.DRAFT
    if reason:
        payout.notes = (payout.notes or "") + f"\nRejected: {reason}"
    db.flush()
    return payout


def mark_paid(
    db: Session,
    payout_id: uuid.UUID,
    company_id: uuid.UUID,
    user_id: uuid.UUID,
    paid_date: date,
    paid_reference: str | None = None,
) -> CommissionPayout:
    """Mark an approved payout as paid (6.5). Finance-administered."""
    payout = _get_payout(db, payout_id, company_id)
    if payout.status != CommissionPayoutStatus.APPROVED:
        raise CommissionError(
            f"Only APPROVED payouts can be marked as paid. Current status: {payout.status.value}"
        )
    payout.status = CommissionPayoutStatus.PAID
    payout.paid_date = paid_date
    payout.paid_reference = paid_reference
    payout.paid_by_user_id = user_id
    db.flush()
    return payout


def cancel_payout(
    db: Session,
    payout_id: uuid.UUID,
    company_id: uuid.UUID,
) -> CommissionPayout:
    """Cancel a DRAFT or PENDING_APPROVAL payout."""
    payout = _get_payout(db, payout_id, company_id)
    if payout.status in (CommissionPayoutStatus.PAID,):
        raise CommissionError("Cannot cancel a payout that has already been paid.")
    payout.status = CommissionPayoutStatus.CANCELLED
    db.flush()
    return payout


def create_clawback(
    db: Session,
    company_id: uuid.UUID,
    invoice: Invoice,
    user_id: uuid.UUID,
    reason: str,
) -> CommissionPayout | None:
    """Create a clawback payout when an invoice is written off (6.4).

    Finds all receipt allocations against this invoice that would have
    generated commission, calculates the total, and creates a negative
    payout record. Clawbacks are auto-approved (they reduce what's owed).

    Returns None if no commission was ever earned on this invoice.
    """
    rate = _commission_rate(db, company_id)
    if rate == 0:
        return None

    contract = db.get(Contract, invoice.contract_id) if invoice.contract_id else None
    sales_staff_id = contract.sales_staff_id if contract else None
    if sales_staff_id is None:
        return None

    # Calculate total commission that was earned on this invoice's allocations.
    allocations = (
        db.query(PaymentAllocation)
        .filter(
            PaymentAllocation.invoice_id == invoice.id,
            PaymentAllocation.company_id == company_id,
        )
        .all()
    )
    if not allocations:
        return None

    total_commission = Decimal("0.00")
    for alloc in allocations:
        if Decimal(invoice.total_amount_sgd) == 0:
            continue
        net_share = (
            Decimal(alloc.amount_sgd)
            * Decimal(invoice.amount_sgd)
            / Decimal(invoice.total_amount_sgd)
        )
        gp_ratio = invoice.gp_percent / Decimal(100)
        commission = (net_share * gp_ratio * rate / Decimal(100)).quantize(Decimal("0.01"))
        total_commission += commission

    if total_commission == 0:
        return None

    today = date.today()
    month_str = today.strftime("%Y-%m")
    period_start, period_end = _month_range(month_str)

    clawback = CommissionPayout(
        company_id=company_id,
        payout_number=next_document_number(db, company_id, "CP"),
        payout_type=CommissionPayoutType.CLAWBACK,
        status=CommissionPayoutStatus.APPROVED,  # Auto-approved
        sales_staff_id=sales_staff_id,
        period_month=month_str,
        period_start=period_start,
        period_end=period_end,
        amount_sgd=-total_commission,  # Negative
        rate_percent=rate,
        clawback_invoice_id=invoice.id,
        clawback_reason=reason,
        submitted_by_user_id=user_id,
        submitted_at=datetime.now(),
        approved_by_user_id=user_id,
        approved_at=datetime.now(),
    )
    db.add(clawback)
    db.flush()
    return clawback


def _get_payout(
    db: Session, payout_id: uuid.UUID, company_id: uuid.UUID
) -> CommissionPayout:
    payout = db.get(CommissionPayout, payout_id)
    if not payout or payout.company_id != company_id:
        raise CommissionError("Commission payout not found.")
    return payout
