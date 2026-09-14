"""
Accounts Payable API -- purchase orders, supplier invoices (2-way
matched per PUR-002) and Payment Vouchers. The supplier party itself is
a Company/Individual record flagged is_supplier=True -- see
app/models/payables.py's module docstring and app/routers/company_individuals.py
for supplier CRUD, which lives there, not here (2026-09-12).

See app/services/payables.py for the rules themselves.
"""
import uuid
from datetime import date
from decimal import Decimal

from fastapi import APIRouter, Depends, HTTPException
from fastapi.responses import StreamingResponse
from sqlalchemy.orm import Session, selectinload

from app.core.database import get_db
from app.models.core import Company, User
from app.models.company_individuals import CompanyIndividual
from app.models.groups import AccessLevel
from app.models.payables import (
    BillStatus,
    PurchaseOrder,
    PurchaseOrderStatus,
    SupplierInvoice,
    SupplierPayment,
)
from app.schemas.schemas import (
    APAgingReport,
    APAgingRow,
    PurchaseOrderCreate,
    PurchaseOrderOut,
    SupplierAllocateRequest,
    SupplierInvoiceCreate,
    SupplierInvoiceOut,
    SupplierPaymentCreate,
    SupplierPaymentOut,
)
from app.services import audit, docx_forms, document_email, exports, posting
from app.schemas.schemas import ReverseRequest
from app.services import payables as ap_svc
from app.services.accounts_receivable import aging_bucket_for
from app.services.authority import require_module_access
from app.services.numbering import next_document_number
from app.models.periods import PeriodDocType, PeriodOperation
from app.services.periods import PeriodClosedError, PeriodLockedError, require_period_allows
from app.services.tax import apply_gst

router = APIRouter(prefix="/api/accounts-payable", tags=["accounts-payable"])
MODULE = "accounts_payable"

PURCHASE_ORDER_EXPORT_FIELDS = [
    "po_number", "supplier_name", "order_date", "description", "amount_sgd",
    "gst_amount_sgd", "total_amount_sgd", "status",
]
BILL_EXPORT_FIELDS = [
    "bill_number", "supplier_invoice_no", "supplier_name", "invoice_date", "due_date",
    "description", "amount_sgd", "gst_amount_sgd", "total_amount_sgd", "amount_paid_sgd",
    "outstanding_sgd", "match_status", "status",
]
PAYMENT_EXPORT_FIELDS = [
    "voucher_number", "supplier_name", "payment_date", "amount_sgd", "allocated_sgd",
    "unallocated_sgd", "method", "reference",
]
AP_AGING_EXPORT_FIELDS = [
    "supplier_name", "current", "days_1_30", "days_31_60", "days_61_90", "over_90", "total",
]


def _supplier_or_404(db: Session, supplier_id: uuid.UUID, company_id: uuid.UUID) -> CompanyIndividual:
    """A "supplier" is a Company/Individual record flagged
    is_supplier=True (2026-09-12) -- not a separate master. Manage the
    flag itself on the Accounts Payable/Company-Individual page; this
    only accepts records already ticked, so a PO/bill/payment can never
    be raised against a party nobody has marked as a supplier yet."""
    s = db.get(CompanyIndividual, supplier_id)
    if not s or s.company_id != company_id or not s.is_supplier:
        raise HTTPException(
            status_code=404,
            detail="Supplier not found -- check it exists and is marked 'Is Supplier' on the Company/Individual page.",
        )
    return s


def _bill_or_404(db: Session, bill_id: uuid.UUID, company_id: uuid.UUID) -> SupplierInvoice:
    b = db.get(SupplierInvoice, bill_id)
    if not b or b.company_id != company_id:
        raise HTTPException(status_code=404, detail="Supplier invoice not found")
    return b


def _po_or_404(db: Session, po_id: uuid.UUID, company_id: uuid.UUID) -> PurchaseOrder:
    po = db.get(PurchaseOrder, po_id)
    if not po or po.company_id != company_id:
        raise HTTPException(status_code=404, detail="Purchase order not found")
    return po


# ---- Purchase orders ------------------------------------------------


def _filter_purchase_orders(
    db: Session,
    company_id: uuid.UUID,
    supplier_id: uuid.UUID | None,
    status: PurchaseOrderStatus | None,
) -> list[PurchaseOrder]:
    q = db.query(PurchaseOrder).filter(PurchaseOrder.company_id == company_id)
    if supplier_id:
        q = q.filter(PurchaseOrder.supplier_id == supplier_id)
    if status:
        q = q.filter(PurchaseOrder.status == status)
    return q.order_by(PurchaseOrder.order_date.desc()).all()


@router.get("/purchase-orders", response_model=list[PurchaseOrderOut])
def list_purchase_orders(
    supplier_id: uuid.UUID | None = None,
    status: PurchaseOrderStatus | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    orders = _filter_purchase_orders(db, current_user.company_id, supplier_id, status)
    return [PurchaseOrderOut.from_model(po) for po in orders]


def _purchase_order_row(po: PurchaseOrder, supplier_name: str) -> dict:
    return {
        "po_number": po.po_number,
        "supplier_name": supplier_name,
        "order_date": po.order_date.isoformat(),
        "description": po.description,
        "amount_sgd": f"{float(po.amount_sgd):.2f}",
        "gst_amount_sgd": f"{float(po.gst_amount_sgd):.2f}",
        "total_amount_sgd": f"{float(po.total_amount_sgd):.2f}",
        "status": po.status.value,
    }


@router.get("/purchase-orders/export.csv")
def export_purchase_orders_csv(
    supplier_id: uuid.UUID | None = None,
    status: PurchaseOrderStatus | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    orders = _filter_purchase_orders(db, current_user.company_id, supplier_id, status)
    suppliers = {s.id: s.name for s in db.query(CompanyIndividual).filter(CompanyIndividual.company_id == current_user.company_id)}
    rows = [_purchase_order_row(po, suppliers.get(po.supplier_id, "")) for po in orders]
    csv_text = exports.rows_to_csv(PURCHASE_ORDER_EXPORT_FIELDS, rows)
    return StreamingResponse(
        iter([csv_text]),
        media_type="text/csv",
        headers={"Content-Disposition": "attachment; filename=purchase-orders.csv"},
    )


@router.get("/purchase-orders/export.xlsx")
def export_purchase_orders_excel(
    supplier_id: uuid.UUID | None = None,
    status: PurchaseOrderStatus | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    orders = _filter_purchase_orders(db, current_user.company_id, supplier_id, status)
    suppliers = {s.id: s.name for s in db.query(CompanyIndividual).filter(CompanyIndividual.company_id == current_user.company_id)}
    rows = [_purchase_order_row(po, suppliers.get(po.supplier_id, "")) for po in orders]
    data = exports.rows_to_excel(PURCHASE_ORDER_EXPORT_FIELDS, rows, sheet_name="Purchase Orders")
    return StreamingResponse(
        iter([data]),
        media_type="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        headers={"Content-Disposition": "attachment; filename=purchase-orders.xlsx"},
    )


@router.get("/purchase-orders/{po_id}", response_model=PurchaseOrderOut)
def get_purchase_order(
    po_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    po = _po_or_404(db, po_id, current_user.company_id)
    return PurchaseOrderOut.from_model(po)


@router.post("/purchase-orders", response_model=PurchaseOrderOut)
def create_purchase_order(
    payload: PurchaseOrderCreate,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    """Raise a PO. GST is applied at the company's rate; whether it then
    needs the owner's approval is PUR-001's value test."""
    _supplier_or_404(db, payload.supplier_id, current_user.company_id)
    _code, _rate, gst, total = apply_gst(
        db, company_id=current_user.company_id, net_amount=Decimal(str(payload.amount_sgd))
    )

    needs_owner = ap_svc.po_needs_owner_approval(db, current_user.company_id, total)
    po = PurchaseOrder(
        company_id=current_user.company_id,
        supplier_id=payload.supplier_id,
        po_number=next_document_number(
            db, company_id=current_user.company_id, doc_kind="purchase_order"
        ),
        order_date=payload.order_date,
        description=payload.description,
        amount_sgd=Decimal(str(payload.amount_sgd)),
        gst_amount_sgd=gst,
        total_amount_sgd=total,
        status=PurchaseOrderStatus.PENDING_APPROVAL if needs_owner else PurchaseOrderStatus.DRAFT,
    )
    db.add(po)
    db.flush()
    audit.record(
        db,
        entity_type="purchase_order",
        entity_id=po.id,
        action="created",
        actor_user_id=current_user.id,
        details=f"{po.po_number}: {payload.description} SGD {total}",
        new_value={"po_number": po.po_number, "total_sgd": str(total), "status": po.status.value},
    )
    db.commit()
    db.refresh(po)
    return PurchaseOrderOut.from_model(po)


@router.post("/purchase-orders/{po_id}/approve", response_model=PurchaseOrderOut)
def approve_po(
    po_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    """PUR-001: value-based approval -- the "confirm" step before a PO
    can be imported to AP (see import_purchase_order_to_ap below)."""
    po = _po_or_404(db, po_id, current_user.company_id)
    try:
        ap_svc.approve_purchase_order(db, po, actor=current_user)
    except ap_svc.PayablesRuleViolation as e:
        raise HTTPException(status_code=422, detail=str(e))

    audit.record(
        db,
        entity_type="purchase_order",
        entity_id=po.id,
        action="approved",
        actor_user_id=current_user.id,
        details=f"{po.po_number} SGD {po.total_amount_sgd}",
        new_value={"status": "approved"},
    )
    db.commit()
    db.refresh(po)
    return PurchaseOrderOut.from_model(po)


@router.post("/purchase-orders/{po_id}/import-to-ap", response_model=SupplierInvoiceOut)
def import_purchase_order_to_ap(
    po_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    """"Confirm and import to AP" (2026-09-12): turn an approved PO
    straight into its matching bill, rather than re-typing the same
    supplier/description/amount into "Record a supplier bill" by hand.
    The new bill is 2-way matched against this same PO (PUR-002), and
    since the amounts are copied exactly it auto-approves for payment
    (PUR-003)."""
    po = _po_or_404(db, po_id, current_user.company_id)
    try:
        ap_svc.assert_po_importable_to_ap(po)
        require_period_allows(
            db, current_user.company_id, date.today(),
            PeriodDocType.PURCHASE_BILL, PeriodOperation.UPDATE,
        )
    except ap_svc.PayablesRuleViolation as e:
        raise HTTPException(status_code=422, detail=str(e))
    except (PeriodLockedError, PeriodClosedError) as e:
        raise HTTPException(status_code=422, detail=str(e))

    bill = SupplierInvoice(
        company_id=current_user.company_id,
        supplier_id=po.supplier_id,
        purchase_order_id=po.id,
        bill_number=next_document_number(
            db, company_id=current_user.company_id, doc_kind="supplier_invoice"
        ),
        invoice_date=date.today(),
        due_date=ap_svc.due_date_for_bill(db, po.supplier_id, date.today()),
        description=po.description,
        amount_sgd=po.amount_sgd,
        gst_amount_sgd=po.gst_amount_sgd,
        total_amount_sgd=po.total_amount_sgd,
    )
    db.add(bill)
    db.flush()
    ap_svc.match_bill_to_po(db, bill, actor_user_id=current_user.id)

    audit.record(
        db,
        entity_type="purchase_order",
        entity_id=po.id,
        action="imported_to_ap",
        actor_user_id=current_user.id,
        details=f"{po.po_number} -> {bill.bill_number}",
        new_value={"bill_number": bill.bill_number},
    )
    db.commit()
    db.refresh(bill)
    # Posted at import (match_bill_to_po -> approved), so return it decorated.
    return posting.decorate(db, current_user.company_id, [SupplierInvoiceOut.model_validate(bill)], posting.SOURCE_SUPPLIER_INVOICE)[0]


@router.get("/purchase-orders/{po_id}/export.docx")
def export_purchase_order_docx(
    po_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    po = _po_or_404(db, po_id, current_user.company_id)
    supplier = _supplier_or_404(db, po.supplier_id, current_user.company_id)
    company = db.get(Company, current_user.company_id)
    data = docx_forms.purchase_order_to_docx(po, supplier, company)
    return StreamingResponse(
        iter([data]),
        media_type="application/vnd.openxmlformats-officedocument.wordprocessingml.document",
        headers={"Content-Disposition": f"attachment; filename={po.po_number}.docx"},
    )


@router.post("/purchase-orders/{po_id}/email")
def email_purchase_order(
    po_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    """Real server-side send (2026-09-12), PO PDF attached. The PDF is
    the same .docx form (see docx_forms.purchase_order_to_docx)
    converted via LibreOffice headless -- see services/pdf_convert.py."""
    po = _po_or_404(db, po_id, current_user.company_id)
    supplier = _supplier_or_404(db, po.supplier_id, current_user.company_id)
    if not supplier.billing_email:
        raise HTTPException(
            status_code=422,
            detail=f"{supplier.name} has no email on file -- add one on the Company/Individual page first.",
        )
    company = db.get(Company, current_user.company_id)

    docx_bytes = docx_forms.purchase_order_to_docx(po, supplier, company)
    body = (
        f"Dear {supplier.name},\n\n"
        f"Please find attached Purchase Order {po.po_number} dated {po.order_date.isoformat()} "
        f"for SGD {float(po.total_amount_sgd):.2f}.\n\n"
        "Please confirm receipt and quote the PO number on your invoice.\n\n"
        f"Regards,\n{company.name if company else ''}"
    )
    try:
        document_email.send_document_email(
            to_email=supplier.billing_email,
            subject=f"Purchase Order {po.po_number} - {company.name if company else ''}",
            body_text=body,
            docx_bytes=docx_bytes,
            filename_stem=po.po_number,
        )
    except document_email.DocumentEmailError as e:
        raise HTTPException(status_code=e.status_code, detail=str(e))

    audit.record(
        db,
        entity_type="purchase_order",
        entity_id=po.id,
        action="emailed",
        actor_user_id=current_user.id,
        details=f"{po.po_number} emailed to {supplier.billing_email}",
    )
    db.commit()
    return {"sent": True, "to": supplier.billing_email}


# ---- Supplier invoices (bills) --------------------------------------


def _filter_bills(
    db: Session,
    company_id: uuid.UUID,
    supplier_id: uuid.UUID | None,
    status: BillStatus | None,
) -> list[SupplierInvoice]:
    q = db.query(SupplierInvoice).filter(SupplierInvoice.company_id == company_id)
    if supplier_id:
        q = q.filter(SupplierInvoice.supplier_id == supplier_id)
    if status:
        q = q.filter(SupplierInvoice.status == status)
    return q.order_by(SupplierInvoice.invoice_date.desc()).all()


@router.get("/bills", response_model=list[SupplierInvoiceOut])
def list_bills(
    supplier_id: uuid.UUID | None = None,
    status: BillStatus | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    bills = _filter_bills(db, current_user.company_id, supplier_id, status)
    # GL status chips (ACC-001): one lookup for the whole list.
    return posting.decorate(
        db, current_user.company_id, [SupplierInvoiceOut.model_validate(b) for b in bills], posting.SOURCE_SUPPLIER_INVOICE
    )


def _bill_row(b: SupplierInvoice, supplier_name: str) -> dict:
    return {
        "bill_number": b.bill_number,
        "supplier_invoice_no": b.supplier_invoice_no or "",
        "supplier_name": supplier_name,
        "invoice_date": b.invoice_date.isoformat(),
        "due_date": b.due_date.isoformat() if b.due_date else "",
        "description": b.description,
        "amount_sgd": f"{float(b.amount_sgd):.2f}",
        "gst_amount_sgd": f"{float(b.gst_amount_sgd):.2f}",
        "total_amount_sgd": f"{float(b.total_amount_sgd):.2f}",
        "amount_paid_sgd": f"{float(b.amount_paid_sgd):.2f}",
        "outstanding_sgd": f"{float(b.outstanding_sgd):.2f}",
        "match_status": b.match_status.value,
        "status": b.status.value,
    }


@router.get("/bills/export.csv")
def export_bills_csv(
    supplier_id: uuid.UUID | None = None,
    status: BillStatus | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    bills = _filter_bills(db, current_user.company_id, supplier_id, status)
    suppliers = {s.id: s.name for s in db.query(CompanyIndividual).filter(CompanyIndividual.company_id == current_user.company_id)}
    rows = [_bill_row(b, suppliers.get(b.supplier_id, "")) for b in bills]
    csv_text = exports.rows_to_csv(BILL_EXPORT_FIELDS, rows)
    return StreamingResponse(
        iter([csv_text]),
        media_type="text/csv",
        headers={"Content-Disposition": "attachment; filename=bills.csv"},
    )


@router.get("/bills/export.xlsx")
def export_bills_excel(
    supplier_id: uuid.UUID | None = None,
    status: BillStatus | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    bills = _filter_bills(db, current_user.company_id, supplier_id, status)
    suppliers = {s.id: s.name for s in db.query(CompanyIndividual).filter(CompanyIndividual.company_id == current_user.company_id)}
    rows = [_bill_row(b, suppliers.get(b.supplier_id, "")) for b in bills]
    data = exports.rows_to_excel(BILL_EXPORT_FIELDS, rows, sheet_name="Bills")
    return StreamingResponse(
        iter([data]),
        media_type="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        headers={"Content-Disposition": "attachment; filename=bills.xlsx"},
    )


@router.post("/bills", response_model=SupplierInvoiceOut)
def create_bill(
    payload: SupplierInvoiceCreate,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    """Enter a supplier invoice. It is 2-way matched against its PO
    immediately (PUR-002); a match auto-approves it for payment
    (PUR-003), a mismatch becomes an exception."""
    _supplier_or_404(db, payload.supplier_id, current_user.company_id)
    try:
        require_period_allows(
            db, current_user.company_id, payload.invoice_date,
            PeriodDocType.PURCHASE_BILL, PeriodOperation.UPDATE,
        )
    except (PeriodLockedError, PeriodClosedError) as e:
        raise HTTPException(status_code=422, detail=str(e))
    net = Decimal(str(payload.amount_sgd))
    gst = Decimal(str(payload.gst_amount_sgd))

    bill = SupplierInvoice(
        company_id=current_user.company_id,
        supplier_id=payload.supplier_id,
        purchase_order_id=payload.purchase_order_id,
        bill_number=next_document_number(
            db, company_id=current_user.company_id, doc_kind="supplier_invoice"
        ),
        supplier_invoice_no=payload.supplier_invoice_no,
        invoice_date=payload.invoice_date,
        due_date=ap_svc.due_date_for_bill(db, payload.supplier_id, payload.invoice_date),
        description=payload.description,
        amount_sgd=net,
        gst_amount_sgd=gst,
        total_amount_sgd=net + gst,
        expense_account_id=payload.expense_account_id,
    )
    db.add(bill)
    db.flush()

    try:
        ap_svc.match_bill_to_po(db, bill, actor_user_id=current_user.id)
    except ap_svc.PayablesRuleViolation as e:
        raise HTTPException(status_code=422, detail=str(e))

    audit.record(
        db,
        entity_type="supplier_invoice",
        entity_id=bill.id,
        action="received",
        actor_user_id=current_user.id,
        details=f"{bill.bill_number}: {bill.match_note}",
        new_value={
            "bill_number": bill.bill_number,
            "total_sgd": str(bill.total_amount_sgd),
            "match_status": bill.match_status.value,
            "status": bill.status.value,
        },
    )
    db.commit()
    db.refresh(bill)
    return posting.decorate(db, current_user.company_id, [SupplierInvoiceOut.model_validate(bill)], posting.SOURCE_SUPPLIER_INVOICE)[0]


# ---- Payment vouchers -----------------------------------------------


def _filter_supplier_payments(
    db: Session, company_id: uuid.UUID, supplier_id: uuid.UUID | None
) -> list[SupplierPayment]:
    q = (
        db.query(SupplierPayment)
        .options(selectinload(SupplierPayment.allocations))
        .filter(SupplierPayment.company_id == company_id)
    )
    if supplier_id:
        q = q.filter(SupplierPayment.supplier_id == supplier_id)
    return q.order_by(SupplierPayment.payment_date.desc()).all()


def _bill_numbers(db: Session, company_id: uuid.UUID) -> dict:
    return {
        b.id: b.bill_number
        for b in db.query(SupplierInvoice).filter(SupplierInvoice.company_id == company_id).all()
    }


@router.get("/payments", response_model=list[SupplierPaymentOut])
def list_supplier_payments(
    supplier_id: uuid.UUID | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    payments = _filter_supplier_payments(db, current_user.company_id, supplier_id)
    numbers = _bill_numbers(db, current_user.company_id)
    return posting.decorate(
        db, current_user.company_id,
        [SupplierPaymentOut.from_model(p, numbers) for p in payments],
        posting.SOURCE_SUPPLIER_PAYMENT, with_bank=True,
    )


def _payment_row(p: SupplierPayment, supplier_name: str) -> dict:
    return {
        "voucher_number": p.voucher_number,
        "supplier_name": supplier_name,
        "payment_date": p.payment_date.isoformat(),
        "amount_sgd": f"{float(p.amount_sgd):.2f}",
        "allocated_sgd": f"{float(p.allocated_sgd):.2f}",
        "unallocated_sgd": f"{float(p.unallocated_sgd):.2f}",
        "method": p.method,
        "reference": p.reference or "",
    }


@router.get("/payments/export.csv")
def export_supplier_payments_csv(
    supplier_id: uuid.UUID | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    payments = _filter_supplier_payments(db, current_user.company_id, supplier_id)
    suppliers = {s.id: s.name for s in db.query(CompanyIndividual).filter(CompanyIndividual.company_id == current_user.company_id)}
    rows = [_payment_row(p, suppliers.get(p.supplier_id, "")) for p in payments]
    csv_text = exports.rows_to_csv(PAYMENT_EXPORT_FIELDS, rows)
    return StreamingResponse(
        iter([csv_text]),
        media_type="text/csv",
        headers={"Content-Disposition": "attachment; filename=payment-vouchers.csv"},
    )


@router.get("/payments/export.xlsx")
def export_supplier_payments_excel(
    supplier_id: uuid.UUID | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    payments = _filter_supplier_payments(db, current_user.company_id, supplier_id)
    suppliers = {s.id: s.name for s in db.query(CompanyIndividual).filter(CompanyIndividual.company_id == current_user.company_id)}
    rows = [_payment_row(p, suppliers.get(p.supplier_id, "")) for p in payments]
    data = exports.rows_to_excel(PAYMENT_EXPORT_FIELDS, rows, sheet_name="Payment Vouchers")
    return StreamingResponse(
        iter([data]),
        media_type="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        headers={"Content-Disposition": "attachment; filename=payment-vouchers.xlsx"},
    )


@router.get("/payments/{payment_id}", response_model=SupplierPaymentOut)
def get_supplier_payment(
    payment_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    payment = (
        db.query(SupplierPayment)
        .options(selectinload(SupplierPayment.allocations))
        .filter(
            SupplierPayment.id == payment_id,
            SupplierPayment.company_id == current_user.company_id,
        )
        .first()
    )
    if not payment:
        raise HTTPException(status_code=404, detail="Payment voucher not found")
    numbers = _bill_numbers(db, current_user.company_id)
    return posting.decorate(
        db, current_user.company_id, [SupplierPaymentOut.from_model(payment, numbers)],
        posting.SOURCE_SUPPLIER_PAYMENT, with_bank=True,
    )[0]


@router.get("/payments/{payment_id}/export.docx")
def export_supplier_payment_docx(
    payment_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    payment = (
        db.query(SupplierPayment)
        .options(selectinload(SupplierPayment.allocations))
        .filter(
            SupplierPayment.id == payment_id,
            SupplierPayment.company_id == current_user.company_id,
        )
        .first()
    )
    if not payment:
        raise HTTPException(status_code=404, detail="Payment voucher not found")
    supplier = _supplier_or_404(db, payment.supplier_id, current_user.company_id)
    company = db.get(Company, current_user.company_id)
    data = docx_forms.payment_voucher_to_docx(
        payment, supplier, company, _bill_numbers(db, current_user.company_id)
    )
    return StreamingResponse(
        iter([data]),
        media_type="application/vnd.openxmlformats-officedocument.wordprocessingml.document",
        headers={"Content-Disposition": f"attachment; filename={payment.voucher_number}.docx"},
    )


@router.post("/payments/{payment_id}/email")
def email_supplier_payment(
    payment_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    """Email PV (2026-09-12) -- same real-send pattern as Purchase Order."""
    payment = (
        db.query(SupplierPayment)
        .options(selectinload(SupplierPayment.allocations))
        .filter(
            SupplierPayment.id == payment_id,
            SupplierPayment.company_id == current_user.company_id,
        )
        .first()
    )
    if not payment:
        raise HTTPException(status_code=404, detail="Payment voucher not found")
    supplier = _supplier_or_404(db, payment.supplier_id, current_user.company_id)
    if not supplier.billing_email:
        raise HTTPException(
            status_code=422,
            detail=f"{supplier.name} has no email on file -- add one on the Company/Individual page first.",
        )
    company = db.get(Company, current_user.company_id)
    docx_bytes = docx_forms.payment_voucher_to_docx(
        payment, supplier, company, _bill_numbers(db, current_user.company_id)
    )
    body = (
        f"Dear {supplier.name},\n\n"
        f"Please find attached Payment Voucher {payment.voucher_number} dated "
        f"{payment.payment_date.isoformat()} for SGD {float(payment.amount_sgd):.2f}.\n\n"
        f"Regards,\n{company.name if company else ''}"
    )
    try:
        document_email.send_document_email(
            to_email=supplier.billing_email,
            subject=f"Payment Voucher {payment.voucher_number} - {company.name if company else ''}",
            body_text=body,
            docx_bytes=docx_bytes,
            filename_stem=payment.voucher_number,
        )
    except document_email.DocumentEmailError as e:
        raise HTTPException(status_code=e.status_code, detail=str(e))

    audit.record(
        db,
        entity_type="supplier_payment",
        entity_id=payment.id,
        action="emailed",
        actor_user_id=current_user.id,
        details=f"{payment.voucher_number} emailed to {supplier.billing_email}",
    )
    db.commit()
    return {"sent": True, "to": supplier.billing_email}


@router.post("/payments", response_model=SupplierPaymentOut)
def create_payment_voucher(
    payload: SupplierPaymentCreate,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    """Raise a Payment Voucher (PV). Like AR, allocation is a separate
    manual decision -- money can be paid and allocated afterwards."""
    supplier = _supplier_or_404(db, payload.supplier_id, current_user.company_id)
    try:
        require_period_allows(
            db, current_user.company_id, payload.payment_date,
            PeriodDocType.PAYMENT_VOUCHER, PeriodOperation.UPDATE,
        )
    except (PeriodLockedError, PeriodClosedError) as e:
        raise HTTPException(status_code=422, detail=str(e))

    payment = SupplierPayment(
        company_id=current_user.company_id,
        supplier_id=payload.supplier_id,
        voucher_number=next_document_number(
            db, company_id=current_user.company_id, doc_kind="payment"
        ),
        payment_date=payload.payment_date,
        amount_sgd=Decimal(str(payload.amount_sgd)),
        method=payload.method,
        reference=payload.reference,
        notes=payload.notes,
        bank_account_id=payload.bank_account_id,
        paid_by_user_id=current_user.id,
    )
    db.add(payment)
    db.flush()

    # ACC-001/003: the payment is an accounting event -- post it now
    # (Dr AP / Cr bank). The explicit Bank step (ACC-002) is separate.
    try:
        posting.post_supplier_payment(db, payment, actor_user_id=current_user.id)
    except posting.PostingError as e:
        raise HTTPException(status_code=422, detail=str(e))

    for entry in payload.allocations:
        bill = _bill_or_404(db, entry.supplier_invoice_id, current_user.company_id)
        try:
            ap_svc.allocate_supplier_payment(db, payment, bill, Decimal(str(entry.amount_sgd)))
        except ap_svc.PayablesRuleViolation as e:
            raise HTTPException(status_code=422, detail=str(e))

    audit.record(
        db,
        entity_type="supplier_payment",
        entity_id=payment.id,
        action="recorded",
        actor_user_id=current_user.id,
        details=f"{payment.voucher_number}: SGD {payload.amount_sgd} to {supplier.name}",
        new_value={
            "voucher_number": payment.voucher_number,
            "amount_sgd": str(payload.amount_sgd),
            "supplier": supplier.name,
        },
    )
    db.commit()
    db.refresh(payment)
    return posting.decorate(
        db, current_user.company_id, [SupplierPaymentOut.from_model(payment)],
        posting.SOURCE_SUPPLIER_PAYMENT, with_bank=True,
    )[0]


@router.post("/payments/{payment_id}/allocate", response_model=SupplierPaymentOut)
def allocate_supplier_payment(
    payment_id: uuid.UUID,
    payload: SupplierAllocateRequest,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    payment = (
        db.query(SupplierPayment)
        .options(selectinload(SupplierPayment.allocations))
        .filter(
            SupplierPayment.id == payment_id,
            SupplierPayment.company_id == current_user.company_id,
        )
        .first()
    )
    if not payment:
        raise HTTPException(status_code=404, detail="Payment voucher not found")

    applied = []
    for entry in payload.allocations:
        bill = _bill_or_404(db, entry.supplier_invoice_id, current_user.company_id)
        try:
            ap_svc.allocate_supplier_payment(db, payment, bill, Decimal(str(entry.amount_sgd)))
        except ap_svc.PayablesRuleViolation as e:
            raise HTTPException(status_code=422, detail=str(e))
        applied.append(f"{bill.bill_number}={entry.amount_sgd}")

    audit.record(
        db,
        entity_type="supplier_payment",
        entity_id=payment.id,
        action="allocated",
        actor_user_id=current_user.id,
        details=", ".join(applied),
        new_value={"allocated_to": applied},
    )
    db.commit()
    db.refresh(payment)
    return posting.decorate(
        db, current_user.company_id, [SupplierPaymentOut.from_model(payment)],
        posting.SOURCE_SUPPLIER_PAYMENT, with_bank=True,
    )[0]


def _ap_aging_rows(db: Session, company_id: uuid.UUID, as_at: date | None) -> tuple[date, list[APAgingRow]]:
    as_at = as_at or date.today()
    bills = (
        db.query(SupplierInvoice)
        .filter(
            SupplierInvoice.company_id == company_id,
            SupplierInvoice.status != BillStatus.PAID,
        )
        .all()
    )
    suppliers = {
        s.id: s.name
        for s in db.query(CompanyIndividual).filter(CompanyIndividual.company_id == company_id).all()
    }

    buckets: dict[uuid.UUID, dict[str, Decimal]] = {}
    for bill in bills:
        outstanding = bill.outstanding_sgd
        if outstanding <= 0:
            continue
        row = buckets.setdefault(
            bill.supplier_id,
            {"current": Decimal(0), "1_30": Decimal(0), "31_60": Decimal(0),
             "61_90": Decimal(0), "over_90": Decimal(0)},
        )
        row[aging_bucket_for(bill.due_date, as_at)] += outstanding

    rows = [
        APAgingRow(
            supplier_id=sid,
            supplier_name=suppliers.get(sid, "(unknown)"),
            current=float(b["current"]),
            days_1_30=float(b["1_30"]),
            days_31_60=float(b["31_60"]),
            days_61_90=float(b["61_90"]),
            over_90=float(b["over_90"]),
            total=float(sum(b.values())),
        )
        for sid, b in buckets.items()
    ]
    rows.sort(key=lambda r: r.total, reverse=True)
    return as_at, rows


@router.get("/aging", response_model=APAgingReport)
def ap_aging(
    as_at: date | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    """What we owe suppliers, bucketed by how far past due it is."""
    resolved_as_at, rows = _ap_aging_rows(db, current_user.company_id, as_at)
    return APAgingReport(as_at=resolved_as_at, rows=rows, total=sum(r.total for r in rows))


def _ap_aging_for_export(db: Session, company_id: uuid.UUID, as_at: date | None) -> list[dict]:
    _resolved_as_at, rows = _ap_aging_rows(db, company_id, as_at)
    return [
        {
            "supplier_name": r.supplier_name,
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
def export_ap_aging_csv(
    as_at: date | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    rows = _ap_aging_for_export(db, current_user.company_id, as_at)
    csv_text = exports.rows_to_csv(AP_AGING_EXPORT_FIELDS, rows)
    return StreamingResponse(
        iter([csv_text]),
        media_type="text/csv",
        headers={"Content-Disposition": "attachment; filename=ap-aging.csv"},
    )


@router.get("/aging/export.xlsx")
def export_ap_aging_excel(
    as_at: date | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    rows = _ap_aging_for_export(db, current_user.company_id, as_at)
    data = exports.rows_to_excel(AP_AGING_EXPORT_FIELDS, rows, sheet_name="AP Aging")
    return StreamingResponse(
        iter([data]),
        media_type="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        headers={"Content-Disposition": "attachment; filename=ap-aging.xlsx"},
    )


# ── GL posting + Bank step (ACC-001..004, docs/gl-posting-design.md) ─────
# Bills post automatically when they reach `approved` (match_bill_to_po);
# payment vouchers post when saved. These are the explicit, reversible
# actions on top: UNGL reverses a posting; Bank / Unbank enter or void the
# payment's line in the bank book.


def _supplier_payment_or_404(db: Session, payment_id: uuid.UUID, company_id: uuid.UUID) -> SupplierPayment:
    payment = (
        db.query(SupplierPayment)
        .filter(SupplierPayment.id == payment_id, SupplierPayment.company_id == company_id)
        .first()
    )
    if payment is None:
        raise HTTPException(status_code=404, detail="Payment voucher not found")
    return payment


@router.post("/bills/{bill_id}/ungl")
def ungl_bill(
    bill_id: uuid.UUID,
    payload: ReverseRequest,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    bill = _bill_or_404(db, bill_id, current_user.company_id)
    try:
        reversal = posting.unpost(
            db, source_type=posting.SOURCE_SUPPLIER_INVOICE, source_id=bill.id,
            actor_user_id=current_user.id, reason=payload.reason, audit_entity_type="supplier_invoice",
        )
    except posting.PostingError as e:
        raise HTTPException(status_code=422, detail=str(e))
    db.commit()
    return {"status": "reversed", "reversal_voucher": reversal.voucher_number}


@router.post("/payments/{payment_id}/ungl")
def ungl_payment_voucher(
    payment_id: uuid.UUID,
    payload: ReverseRequest,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    payment = _supplier_payment_or_404(db, payment_id, current_user.company_id)
    try:
        reversal = posting.unpost(
            db, source_type=posting.SOURCE_SUPPLIER_PAYMENT, source_id=payment.id,
            actor_user_id=current_user.id, reason=payload.reason, audit_entity_type="supplier_payment",
        )
    except posting.PostingError as e:
        raise HTTPException(status_code=422, detail=str(e))
    db.commit()
    return {"status": "reversed", "reversal_voucher": reversal.voucher_number}


@router.post("/payments/{payment_id}/bank")
def bank_payment_voucher(
    payment_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    """ACC-002: Finance confirms the money left the bank -- writes the
    payment into the bank book as one credit line."""
    payment = _supplier_payment_or_404(db, payment_id, current_user.company_id)
    try:
        txn = posting.bank_supplier_payment(db, payment, actor_user_id=current_user.id)
    except posting.PostingError as e:
        raise HTTPException(status_code=422, detail=str(e))
    db.commit()
    return {"status": "banked", "transaction_number": txn.transaction_number}


@router.post("/payments/{payment_id}/unbank")
def unbank_payment_voucher(
    payment_id: uuid.UUID,
    payload: ReverseRequest,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    payment = _supplier_payment_or_404(db, payment_id, current_user.company_id)
    try:
        txn = posting.unbank(
            db, source_type=posting.SOURCE_SUPPLIER_PAYMENT, source_id=payment.id,
            doc_type=PeriodDocType.PAYMENT_VOUCHER, actor_user_id=current_user.id,
            reason=payload.reason, audit_entity_type="supplier_payment",
        )
    except posting.PostingError as e:
        raise HTTPException(status_code=422, detail=str(e))
    db.commit()
    return {"status": "unbanked", "transaction_number": txn.transaction_number}
