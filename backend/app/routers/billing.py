import uuid

from fastapi import APIRouter, Depends, HTTPException
from fastapi.responses import StreamingResponse
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.models.billing import Invoice
from app.models.core import Company, User
from app.models.company_individuals import CompanyIndividual
from app.models.groups import AccessLevel
from app.schemas.schemas import InvoiceOut
from app.services import audit, docx_forms, document_email, posting
from app.services import exports
from app.services.authority import require_module_access

router = APIRouter(prefix="/api/invoices", tags=["billing"])
MODULE = "billing"

INVOICE_EXPORT_FIELDS = [
    "invoice_number", "customer_name", "invoice_type", "description",
    "amount_sgd", "gst_amount_sgd", "total_amount_sgd", "outstanding_sgd",
    "status", "issued_at", "due_date",
]


def _invoice_row(inv: Invoice, customer_name: str) -> dict:
    return {
        "invoice_number": inv.invoice_number,
        "customer_name": customer_name,
        "invoice_type": inv.invoice_type,
        "description": inv.description,
        "amount_sgd": f"{inv.amount_sgd:.2f}",
        "gst_amount_sgd": f"{inv.gst_amount_sgd:.2f}",
        "total_amount_sgd": f"{inv.total_amount_sgd:.2f}",
        "outstanding_sgd": f"{inv.outstanding_sgd:.2f}",
        "status": inv.status.value,
        "issued_at": inv.issued_at.date().isoformat(),
        "due_date": inv.due_date.isoformat() if inv.due_date else "",
    }


def _list_invoices_for_export(
    db: Session, company_id: uuid.UUID, customer_id: uuid.UUID | None, contract_id: uuid.UUID | None
) -> list[dict]:
    query = db.query(Invoice).filter(Invoice.company_id == company_id)
    if customer_id:
        query = query.filter(Invoice.customer_id == customer_id)
    if contract_id:
        query = query.filter(Invoice.contract_id == contract_id)
    invoices = query.order_by(Invoice.issued_at.desc()).all()
    customer_names = {c.id: c.name for c in db.query(CompanyIndividual).filter(CompanyIndividual.company_id == company_id)}
    return [_invoice_row(inv, customer_names.get(inv.customer_id, "")) for inv in invoices]


@router.get("", response_model=list[InvoiceOut])
def list_invoices(
    customer_id: uuid.UUID | None = None,
    contract_id: uuid.UUID | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    query = db.query(Invoice).filter(Invoice.company_id == current_user.company_id)
    if customer_id:
        query = query.filter(Invoice.customer_id == customer_id)
    if contract_id:
        query = query.filter(Invoice.contract_id == contract_id)
    invoices = query.order_by(Invoice.issued_at.desc()).all()
    # GL status chips (ACC-001): one lookup for the whole list.
    return posting.decorate(
        db, current_user.company_id, [InvoiceOut.model_validate(i) for i in invoices], posting.SOURCE_INVOICE
    )


@router.get("/export.csv")
def export_invoices_csv(
    customer_id: uuid.UUID | None = None,
    contract_id: uuid.UUID | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    rows = _list_invoices_for_export(db, current_user.company_id, customer_id, contract_id)
    csv_text = exports.rows_to_csv(INVOICE_EXPORT_FIELDS, rows)
    return StreamingResponse(
        iter([csv_text]),
        media_type="text/csv",
        headers={"Content-Disposition": "attachment; filename=invoices.csv"},
    )


@router.get("/export.xlsx")
def export_invoices_excel(
    customer_id: uuid.UUID | None = None,
    contract_id: uuid.UUID | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    rows = _list_invoices_for_export(db, current_user.company_id, customer_id, contract_id)
    data = exports.rows_to_excel(INVOICE_EXPORT_FIELDS, rows, sheet_name="Invoices")
    return StreamingResponse(
        iter([data]),
        media_type="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        headers={"Content-Disposition": "attachment; filename=invoices.xlsx"},
    )


@router.get("/{invoice_id}", response_model=InvoiceOut)
def get_invoice(
    invoice_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    invoice = db.get(Invoice, invoice_id)
    if not invoice or invoice.company_id != current_user.company_id:
        raise HTTPException(status_code=404, detail="Invoice not found")
    return posting.decorate(db, current_user.company_id, [InvoiceOut.model_validate(invoice)], posting.SOURCE_INVOICE)[0]


@router.get("/{invoice_id}/export.docx")
def export_invoice_docx(
    invoice_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    invoice = db.get(Invoice, invoice_id)
    if not invoice or invoice.company_id != current_user.company_id:
        raise HTTPException(status_code=404, detail="Invoice not found")
    customer = db.get(CompanyIndividual, invoice.customer_id)
    company = db.get(Company, current_user.company_id)
    data = docx_forms.invoice_to_docx(invoice, customer, company)
    return StreamingResponse(
        iter([data]),
        media_type="application/vnd.openxmlformats-officedocument.wordprocessingml.document",
        headers={"Content-Disposition": f"attachment; filename={invoice.invoice_number}.docx"},
    )


@router.post("/{invoice_id}/email")
def email_invoice(
    invoice_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    """Email Sales Invoice (2026-09-12) -- same real-send pattern as
    Purchase Order's Email button."""
    invoice = db.get(Invoice, invoice_id)
    if not invoice or invoice.company_id != current_user.company_id:
        raise HTTPException(status_code=404, detail="Invoice not found")
    customer = db.get(CompanyIndividual, invoice.customer_id)
    if not customer or not customer.billing_email:
        raise HTTPException(
            status_code=422,
            detail="This customer has no email on file -- add one on the Company/Individual page first.",
        )
    company = db.get(Company, current_user.company_id)
    docx_bytes = docx_forms.invoice_to_docx(invoice, customer, company)
    body = (
        f"Dear {customer.name},\n\n"
        f"Please find attached Invoice {invoice.invoice_number} for SGD "
        f"{float(invoice.total_amount_sgd):.2f}"
        + (f", due {invoice.due_date.isoformat()}.\n\n" if invoice.due_date else ".\n\n")
        + f"Regards,\n{company.name if company else ''}"
    )
    try:
        document_email.send_document_email(
            to_email=customer.billing_email,
            subject=f"Invoice {invoice.invoice_number} - {company.name if company else ''}",
            body_text=body,
            docx_bytes=docx_bytes,
            filename_stem=invoice.invoice_number,
        )
    except document_email.DocumentEmailError as e:
        raise HTTPException(status_code=e.status_code, detail=str(e))

    audit.record(
        db,
        entity_type="invoice",
        entity_id=invoice.id,
        action="emailed",
        actor_user_id=current_user.id,
        details=f"{invoice.invoice_number} emailed to {customer.billing_email}",
    )
    db.commit()
    return {"sent": True, "to": customer.billing_email}
