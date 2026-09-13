"""
Accounts Receivable API -- customer payments, manual allocation (AR-001),
aging, statements, write-offs (AR-002) and dispute flagging (AR-003).

See app/services/accounts_receivable.py for the rules themselves.
"""
import uuid
from datetime import date
from decimal import Decimal

from fastapi import APIRouter, Depends, HTTPException
from fastapi.responses import StreamingResponse
from sqlalchemy.orm import Session, selectinload

from app.core.database import get_db
from app.models.billing import Invoice, InvoiceStatus
from app.models.core import Company, User
from app.models.company_individuals import CompanyIndividual
from app.models.groups import AccessLevel
from app.models.payments import Payment, PaymentMethod
from app.schemas.schemas import (
    AgingReport,
    AgingRow,
    AllocateRequest,
    CompanyIndividualStatement,
    InvoiceDisputeRequest,
    InvoiceWriteOffRequest,
    InvoiceOut,
    PaymentCreate,
    PaymentOut,
    StatementLine,
)
from app.services import accounts_receivable as ar_svc
from app.services import audit, docx_forms, document_email, exports
from app.services.authority import require_module_access
from app.services.numbering import next_document_number
from app.models.periods import PeriodDocType, PeriodOperation
from app.services.periods import PeriodClosedError, PeriodLockedError, require_period_allows

router = APIRouter(prefix="/api/accounts-receivable", tags=["accounts-receivable"])
MODULE = "accounts_receivable"

PAYMENT_EXPORT_FIELDS = [
    "voucher_number", "customer_name", "payment_date", "amount_sgd", "allocated_sgd",
    "unallocated_sgd", "method", "reference",
]
AR_AGING_EXPORT_FIELDS = [
    "customer_name", "current", "days_1_30", "days_31_60", "days_61_90", "over_90", "total",
]


def _invoice_or_404(db: Session, invoice_id: uuid.UUID, company_id: uuid.UUID) -> Invoice:
    invoice = db.get(Invoice, invoice_id)
    if not invoice or invoice.company_id != company_id:
        raise HTTPException(status_code=404, detail="Invoice not found")
    return invoice


def _payment_or_404(db: Session, payment_id: uuid.UUID, company_id: uuid.UUID) -> Payment:
    payment = (
        db.query(Payment)
        .options(selectinload(Payment.allocations))
        .filter(Payment.id == payment_id, Payment.company_id == company_id)
        .first()
    )
    if not payment:
        raise HTTPException(status_code=404, detail="Payment not found")
    return payment


def _invoice_numbers(db: Session, company_id: uuid.UUID) -> dict:
    return {
        i.id: i.invoice_number
        for i in db.query(Invoice).filter(Invoice.company_id == company_id).all()
    }


# ---- Payments -------------------------------------------------------


@router.post("/payments", response_model=PaymentOut)
def record_payment(
    payload: PaymentCreate,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    """Record money received. Allocation is optional here -- AR-001 makes
    it a manual decision, so a receipt can sit unallocated on the
    customer's account until Finance decides what it settles."""
    customer = db.get(CompanyIndividual, payload.customer_id)
    if not customer or customer.company_id != current_user.company_id:
        raise HTTPException(status_code=404, detail="Company / Individual not found")

    try:
        method = PaymentMethod(payload.method)
    except ValueError:
        raise HTTPException(status_code=400, detail=f"Unknown payment method '{payload.method}'")

    try:
        require_period_allows(
            db, current_user.company_id, payload.payment_date,
            PeriodDocType.RECEIPT_VOUCHER, PeriodOperation.UPDATE,
        )
    except (PeriodLockedError, PeriodClosedError) as e:
        raise HTTPException(status_code=422, detail=str(e))

    payment = Payment(
        company_id=current_user.company_id,
        customer_id=payload.customer_id,
        voucher_number=next_document_number(
            db, company_id=current_user.company_id, doc_kind="receipt"
        ),
        payment_date=payload.payment_date,
        amount_sgd=Decimal(str(payload.amount_sgd)),
        method=method,
        reference=payload.reference,
        notes=payload.notes,
        recorded_by_user_id=current_user.id,
    )
    db.add(payment)
    db.flush()

    for entry in payload.allocations:
        invoice = _invoice_or_404(db, entry.invoice_id, current_user.company_id)
        try:
            ar_svc.allocate_payment(db, payment, invoice, Decimal(str(entry.amount_sgd)))
        except ar_svc.ARRuleViolation as e:
            raise HTTPException(status_code=422, detail=str(e))

    audit.record(
        db,
        entity_type="payment",
        entity_id=payment.id,
        action="recorded",
        actor_user_id=current_user.id,
        details=(
            f"{payment.voucher_number}: SGD {payload.amount_sgd} from {customer.name} "
            f"({method.value}, ref={payload.reference or '-'})"
        ),
        new_value={
            "amount_sgd": str(payload.amount_sgd),
            "customer": customer.name,
            "allocations": len(payload.allocations),
        },
    )
    db.commit()
    db.refresh(payment)
    return PaymentOut.from_model(payment, _invoice_numbers(db, current_user.company_id))


def _filter_payments(
    db: Session, company_id: uuid.UUID, customer_id: uuid.UUID | None, unallocated_only: bool
) -> list[Payment]:
    query = (
        db.query(Payment)
        .options(selectinload(Payment.allocations))
        .filter(Payment.company_id == company_id)
    )
    if customer_id:
        query = query.filter(Payment.customer_id == customer_id)
    payments = query.order_by(Payment.payment_date.desc(), Payment.created_at.desc()).all()
    if unallocated_only:
        payments = [p for p in payments if p.unallocated_sgd > 0]
    return payments


@router.get("/payments", response_model=list[PaymentOut])
def list_payments(
    customer_id: uuid.UUID | None = None,
    unallocated_only: bool = False,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    payments = _filter_payments(db, current_user.company_id, customer_id, unallocated_only)
    numbers = _invoice_numbers(db, current_user.company_id)
    return [PaymentOut.from_model(p, numbers) for p in payments]


def _payment_row(p: Payment, customer_name: str) -> dict:
    return {
        "voucher_number": p.voucher_number,
        "customer_name": customer_name,
        "payment_date": p.payment_date.isoformat(),
        "amount_sgd": f"{float(p.amount_sgd):.2f}",
        "allocated_sgd": f"{float(p.allocated_sgd):.2f}",
        "unallocated_sgd": f"{float(p.unallocated_sgd):.2f}",
        "method": p.method.value,
        "reference": p.reference or "",
    }


@router.get("/payments/export.csv")
def export_payments_csv(
    customer_id: uuid.UUID | None = None,
    unallocated_only: bool = False,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    payments = _filter_payments(db, current_user.company_id, customer_id, unallocated_only)
    customers = {c.id: c.name for c in db.query(CompanyIndividual).filter(CompanyIndividual.company_id == current_user.company_id)}
    rows = [_payment_row(p, customers.get(p.customer_id, "")) for p in payments]
    csv_text = exports.rows_to_csv(PAYMENT_EXPORT_FIELDS, rows)
    return StreamingResponse(
        iter([csv_text]),
        media_type="text/csv",
        headers={"Content-Disposition": "attachment; filename=receipts.csv"},
    )


@router.get("/payments/export.xlsx")
def export_payments_excel(
    customer_id: uuid.UUID | None = None,
    unallocated_only: bool = False,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    payments = _filter_payments(db, current_user.company_id, customer_id, unallocated_only)
    customers = {c.id: c.name for c in db.query(CompanyIndividual).filter(CompanyIndividual.company_id == current_user.company_id)}
    rows = [_payment_row(p, customers.get(p.customer_id, "")) for p in payments]
    data = exports.rows_to_excel(PAYMENT_EXPORT_FIELDS, rows, sheet_name="Receipts")
    return StreamingResponse(
        iter([data]),
        media_type="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        headers={"Content-Disposition": "attachment; filename=receipts.xlsx"},
    )


@router.get("/payments/{payment_id}", response_model=PaymentOut)
def get_payment(
    payment_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    payment = _payment_or_404(db, payment_id, current_user.company_id)
    return PaymentOut.from_model(payment, _invoice_numbers(db, current_user.company_id))


@router.get("/payments/{payment_id}/export.docx")
def export_payment_docx(
    payment_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    payment = _payment_or_404(db, payment_id, current_user.company_id)
    customer = db.get(CompanyIndividual, payment.customer_id)
    company = db.get(Company, current_user.company_id)
    data = docx_forms.receipt_to_docx(payment, customer, company, _invoice_numbers(db, current_user.company_id))
    return StreamingResponse(
        iter([data]),
        media_type="application/vnd.openxmlformats-officedocument.wordprocessingml.document",
        headers={"Content-Disposition": f"attachment; filename={payment.voucher_number}.docx"},
    )


@router.post("/payments/{payment_id}/email")
def email_receipt(
    payment_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    """Email Receipt Voucher (2026-09-12) -- same real-send pattern as
    Purchase Order's Email button."""
    payment = _payment_or_404(db, payment_id, current_user.company_id)
    customer = db.get(CompanyIndividual, payment.customer_id)
    if not customer or not customer.billing_email:
        raise HTTPException(
            status_code=422,
            detail="This customer has no email on file -- add one on the Company/Individual page first.",
        )
    company = db.get(Company, current_user.company_id)
    docx_bytes = docx_forms.receipt_to_docx(payment, customer, company, _invoice_numbers(db, current_user.company_id))
    body = (
        f"Dear {customer.name},\n\n"
        f"Please find attached Receipt {payment.voucher_number} dated {payment.payment_date.isoformat()} "
        f"for SGD {float(payment.amount_sgd):.2f}.\n\n"
        f"Regards,\n{company.name if company else ''}"
    )
    try:
        document_email.send_document_email(
            to_email=customer.billing_email,
            subject=f"Receipt {payment.voucher_number} - {company.name if company else ''}",
            body_text=body,
            docx_bytes=docx_bytes,
            filename_stem=payment.voucher_number,
        )
    except document_email.DocumentEmailError as e:
        raise HTTPException(status_code=e.status_code, detail=str(e))

    audit.record(
        db,
        entity_type="payment",
        entity_id=payment.id,
        action="emailed",
        actor_user_id=current_user.id,
        details=f"{payment.voucher_number} emailed to {customer.billing_email}",
    )
    db.commit()
    return {"sent": True, "to": customer.billing_email}


@router.post("/payments/{payment_id}/allocate", response_model=PaymentOut)
def allocate_payment(
    payment_id: uuid.UUID,
    payload: AllocateRequest,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    """AR-001: Finance says which invoices this receipt settles."""
    payment = _payment_or_404(db, payment_id, current_user.company_id)

    applied = []
    for entry in payload.allocations:
        invoice = _invoice_or_404(db, entry.invoice_id, current_user.company_id)
        try:
            ar_svc.allocate_payment(db, payment, invoice, Decimal(str(entry.amount_sgd)))
        except ar_svc.ARRuleViolation as e:
            raise HTTPException(status_code=422, detail=str(e))
        applied.append(f"{invoice.invoice_number}={entry.amount_sgd}")

    audit.record(
        db,
        entity_type="payment",
        entity_id=payment.id,
        action="allocated",
        actor_user_id=current_user.id,
        details=", ".join(applied),
        new_value={"allocated_to": applied},
    )
    db.commit()
    db.refresh(payment)
    return PaymentOut.from_model(payment, _invoice_numbers(db, current_user.company_id))


# ---- Invoice actions ------------------------------------------------


@router.post("/invoices/{invoice_id}/write-off", response_model=InvoiceOut)
def write_off_invoice(
    invoice_id: uuid.UUID,
    payload: InvoiceWriteOffRequest,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    """AR-002. The invoice is marked written off, never deleted."""
    invoice = _invoice_or_404(db, invoice_id, current_user.company_id)
    outstanding = invoice.outstanding_sgd
    try:
        ar_svc.write_off_invoice(db, invoice, actor=current_user, reason=payload.reason)
    except ar_svc.ARRuleViolation as e:
        raise HTTPException(status_code=422, detail=str(e))

    audit.record(
        db,
        entity_type="invoice",
        entity_id=invoice.id,
        action="written_off",
        actor_user_id=current_user.id,
        reason=payload.reason,
        details=f"{invoice.invoice_number}, SGD {outstanding} written off as bad debt",
        old_value={"status": "outstanding", "outstanding_sgd": str(outstanding)},
        new_value={"status": "written_off", "outstanding_sgd": "0.00"},
    )

    # Commission clawback (6.4): if commission was earned on receipt
    # allocations against this invoice, create a negative clawback record.
    from app.services.commissions import create_clawback
    clawback = create_clawback(
        db,
        company_id=current_user.company_id,
        invoice=invoice,
        user_id=current_user.id,
        reason=f"Write-off of {invoice.invoice_number}: {payload.reason}",
    )
    if clawback:
        audit.record(
            db,
            entity_type="commission_payout",
            entity_id=clawback.id,
            action="clawback_created",
            actor_user_id=current_user.id,
            new_value={
                "payout_number": clawback.payout_number,
                "amount_sgd": float(clawback.amount_sgd),
                "invoice": invoice.invoice_number,
            },
        )

    db.commit()
    db.refresh(invoice)
    return invoice


@router.post("/invoices/{invoice_id}/dispute", response_model=InvoiceOut)
def flag_dispute(
    invoice_id: uuid.UUID,
    payload: InvoiceDisputeRequest,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    """AR-003: records that an invoice is disputed. It deliberately does
    NOT hold collections -- the invoice keeps aging normally."""
    invoice = _invoice_or_404(db, invoice_id, current_user.company_id)
    was = invoice.is_disputed
    invoice.is_disputed = payload.is_disputed
    invoice.dispute_note = payload.note

    audit.record(
        db,
        entity_type="invoice",
        entity_id=invoice.id,
        action="dispute_flagged" if payload.is_disputed else "dispute_cleared",
        actor_user_id=current_user.id,
        reason=payload.note,
        details=f"{invoice.invoice_number} (AR-003: collections continue regardless)",
        old_value={"is_disputed": was},
        new_value={"is_disputed": payload.is_disputed},
    )
    db.commit()
    db.refresh(invoice)
    return invoice


# ---- Reporting ------------------------------------------------------


def _ar_aging_rows(db: Session, company_id: uuid.UUID, as_at: date | None) -> tuple[date, list[AgingRow]]:
    as_at = as_at or date.today()
    invoices = (
        db.query(Invoice)
        .filter(
            Invoice.company_id == company_id,
            Invoice.status != InvoiceStatus.PAID,
            Invoice.status != InvoiceStatus.WRITTEN_OFF,
        )
        .all()
    )
    customers = {
        c.id: c.name
        for c in db.query(CompanyIndividual).filter(CompanyIndividual.company_id == company_id).all()
    }

    buckets: dict[uuid.UUID, dict[str, Decimal]] = {}
    for invoice in invoices:
        outstanding = invoice.outstanding_sgd
        if outstanding <= 0:
            continue
        row = buckets.setdefault(
            invoice.customer_id,
            {"current": Decimal(0), "1_30": Decimal(0), "31_60": Decimal(0),
             "61_90": Decimal(0), "over_90": Decimal(0)},
        )
        row[ar_svc.aging_bucket_for(invoice.due_date, as_at)] += outstanding

    rows = [
        AgingRow(
            customer_id=customer_id,
            customer_name=customers.get(customer_id, "(unknown)"),
            current=float(b["current"]),
            days_1_30=float(b["1_30"]),
            days_31_60=float(b["31_60"]),
            days_61_90=float(b["61_90"]),
            over_90=float(b["over_90"]),
            total=float(sum(b.values())),
        )
        for customer_id, b in buckets.items()
    ]
    rows.sort(key=lambda r: r.total, reverse=True)
    return as_at, rows


@router.get("/aging", response_model=AgingReport)
def aging_report(
    as_at: date | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    """Outstanding balances bucketed by how far past due they are.

    AR-003: disputed invoices are included like any other -- they are
    flagged in the statement, not excluded from collections."""
    resolved_as_at, rows = _ar_aging_rows(db, current_user.company_id, as_at)

    return AgingReport(
        as_at=resolved_as_at,
        rows=rows,
        current=sum(r.current for r in rows),
        days_1_30=sum(r.days_1_30 for r in rows),
        days_31_60=sum(r.days_31_60 for r in rows),
        days_61_90=sum(r.days_61_90 for r in rows),
        over_90=sum(r.over_90 for r in rows),
        total=sum(r.total for r in rows),
    )


def _ar_aging_for_export(db: Session, company_id: uuid.UUID, as_at: date | None) -> list[dict]:
    _resolved_as_at, rows = _ar_aging_rows(db, company_id, as_at)
    return [
        {
            "customer_name": r.customer_name,
            "current": f"{r.current:.2f}",
            "days_1_30": f"{r.days_1_30:.2f}",
            "days_31_60": f"{r.days_31_60:.2f}",
            "days_61_90": f"{r.days_61_90:.2f}",
            "over_90": f"{r.over_90:.2f}",
            "total": f"{r.total:.2f}",
        }
        for r in rows
    ]


@router.get("/aging/export.csv")
def export_ar_aging_csv(
    as_at: date | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    rows = _ar_aging_for_export(db, current_user.company_id, as_at)
    csv_text = exports.rows_to_csv(AR_AGING_EXPORT_FIELDS, rows)
    return StreamingResponse(
        iter([csv_text]),
        media_type="text/csv",
        headers={"Content-Disposition": "attachment; filename=ar-aging.csv"},
    )


@router.get("/aging/export.xlsx")
def export_ar_aging_excel(
    as_at: date | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    rows = _ar_aging_for_export(db, current_user.company_id, as_at)
    data = exports.rows_to_excel(AR_AGING_EXPORT_FIELDS, rows, sheet_name="AR Aging")
    return StreamingResponse(
        iter([data]),
        media_type="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        headers={"Content-Disposition": "attachment; filename=ar-aging.xlsx"},
    )


def _build_customer_statement(
    db: Session, customer: CompanyIndividual, company_id: uuid.UUID, as_at: date | None
) -> CompanyIndividualStatement:
    """Shared by the JSON endpoint below and the docx/email export --
    "export what's on screen" always matches (2026-09-12)."""
    as_at = as_at or date.today()

    invoices = (
        db.query(Invoice)
        .filter(
            Invoice.company_id == company_id,
            Invoice.customer_id == customer.id,
            Invoice.status != InvoiceStatus.PAID,
        )
        .order_by(Invoice.issued_at)
        .all()
    )

    lines = [
        StatementLine(
            invoice_id=i.id,
            invoice_number=i.invoice_number,
            description=i.description,
            issued_on=i.issued_at.date(),
            due_date=i.due_date,
            total_amount_sgd=float(i.total_amount_sgd),
            amount_paid_sgd=float(i.amount_paid_sgd),
            outstanding_sgd=float(i.outstanding_sgd),
            status=i.status.value,
            is_disputed=i.is_disputed,
            days_overdue=(
                max((as_at - i.due_date).days, 0) if i.due_date else 0
            ),
        )
        for i in invoices
    ]

    payments = (
        db.query(Payment)
        .options(selectinload(Payment.allocations))
        .filter(Payment.company_id == company_id, Payment.customer_id == customer.id)
        .all()
    )
    unallocated = sum((p.unallocated_sgd for p in payments), start=Decimal("0.00"))

    return CompanyIndividualStatement(
        customer_id=customer.id,
        customer_name=customer.name,
        as_at=as_at,
        payment_terms_days=customer.payment_terms_days,
        lines=lines,
        total_outstanding_sgd=float(sum(Decimal(str(l.outstanding_sgd)) for l in lines)),
        unallocated_credit_sgd=float(unallocated),
    )


@router.get("/statement/{customer_id}", response_model=CompanyIndividualStatement)
def customer_statement(
    customer_id: uuid.UUID,
    as_at: date | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    """Everything this customer currently owes, plus any receipt money
    still sitting unallocated on their account."""
    customer = db.get(CompanyIndividual, customer_id)
    if not customer or customer.company_id != current_user.company_id:
        raise HTTPException(status_code=404, detail="Company / Individual not found")
    return _build_customer_statement(db, customer, current_user.company_id, as_at)


@router.get("/statement/{customer_id}/export.docx")
def export_customer_statement_docx(
    customer_id: uuid.UUID,
    as_at: date | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    customer = db.get(CompanyIndividual, customer_id)
    if not customer or customer.company_id != current_user.company_id:
        raise HTTPException(status_code=404, detail="Company / Individual not found")
    statement = _build_customer_statement(db, customer, current_user.company_id, as_at)
    company = db.get(Company, current_user.company_id)
    data = docx_forms.statement_to_docx(statement, customer, company)
    return StreamingResponse(
        iter([data]),
        media_type="application/vnd.openxmlformats-officedocument.wordprocessingml.document",
        headers={
            "Content-Disposition": f"attachment; filename=Statement-{customer.name}-{statement.as_at}.docx"
        },
    )


@router.post("/statement/{customer_id}/email")
def email_customer_statement(
    customer_id: uuid.UUID,
    as_at: date | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    """Email Statement of Accounts (2026-09-12) -- same real-send pattern
    as Purchase Order's Email button."""
    customer = db.get(CompanyIndividual, customer_id)
    if not customer or customer.company_id != current_user.company_id:
        raise HTTPException(status_code=404, detail="Company / Individual not found")
    if not customer.billing_email:
        raise HTTPException(
            status_code=422,
            detail="This customer has no email on file -- add one on the Company/Individual page first.",
        )
    statement = _build_customer_statement(db, customer, current_user.company_id, as_at)
    company = db.get(Company, current_user.company_id)
    docx_bytes = docx_forms.statement_to_docx(statement, customer, company)
    body = (
        f"Dear {customer.name},\n\n"
        f"Please find attached your Statement of Accounts as at {statement.as_at.isoformat()}, "
        f"total outstanding SGD {statement.total_outstanding_sgd:.2f}.\n\n"
        f"Regards,\n{company.name if company else ''}"
    )
    try:
        document_email.send_document_email(
            to_email=customer.billing_email,
            subject=f"Statement of Accounts as at {statement.as_at.isoformat()} - {company.name if company else ''}",
            body_text=body,
            docx_bytes=docx_bytes,
            filename_stem=f"Statement-{customer.name}-{statement.as_at}",
        )
    except document_email.DocumentEmailError as e:
        raise HTTPException(status_code=e.status_code, detail=str(e))

    audit.record(
        db,
        entity_type="customer",
        entity_id=customer.id,
        action="statement_emailed",
        actor_user_id=current_user.id,
        details=f"Statement as at {statement.as_at} emailed to {customer.billing_email}",
    )
    db.commit()
    return {"sent": True, "to": customer.billing_email}
