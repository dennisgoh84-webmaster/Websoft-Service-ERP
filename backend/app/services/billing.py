"""
Billing business logic -- BILL-001 (annual upfront), BILL-002 (no
approval needed), BILL-005 (revenue recognized on invoice), and SRV-008
(excess usage billed at the contract's own blended rate).

Every invoice raised here is a tax invoice: GST is applied per the
company's tax code (confirmed standard-rated), it is serially numbered,
and its due date comes from the customer's own payment terms (confirmed
2026-09-10: terms vary per customer). A customer with no agreed terms
gets no due date rather than an invented one.
"""
import uuid
from datetime import date, timedelta
from decimal import ROUND_HALF_UP, Decimal

from sqlalchemy.orm import Session

from app.models.billing import Invoice, InvoiceType
from app.models.contracts import Contract, ExcessUsageRecord
from app.models.company_individuals import CompanyIndividual
from app.models.quotations import Quotation
from app.services import audit, posting
from app.services.numbering import next_document_number
from app.services.tax import apply_gst


def _cost_basis_for_contract(db: Session, contract: Contract) -> Decimal | None:
    """GP costing (2026-09-12, docs/open-business-decisions.md #32): a
    CONTRACT_ANNUAL invoice's cost is traced back to the Sales Quotation
    that converted into this contract (Quotation.converted_contract_id /
    converted_annual_contract_id -- see app/models/quotations.py), summing
    that quotation's own QuotationLine.cost_sgd values. None (no known
    cost basis) if this contract wasn't created from a quotation, or the
    quotation's lines simply never had a cost entered."""
    quotation = (
        db.query(Quotation)
        .filter(
            (Quotation.converted_contract_id == contract.id)
            | (Quotation.converted_annual_contract_id == contract.id)
        )
        .first()
    )
    if quotation is None:
        return None
    total = sum(
        (Decimal(line.cost_sgd) for line in quotation.lines if line.cost_sgd is not None),
        start=Decimal("0.00"),
    )
    return total if any(line.cost_sgd is not None for line in quotation.lines) else None


def _due_date_for(db: Session, customer_id: uuid.UUID, issued_on: date) -> date | None:
    """Invoice due date from the customer's agreed payment terms. None
    when no terms have been agreed -- see CompanyIndividual.payment_terms_days."""
    customer = db.get(CompanyIndividual, customer_id)
    if customer is None or customer.payment_terms_days is None:
        return None
    return issued_on + timedelta(days=customer.payment_terms_days)


def _build_invoice(
    db: Session,
    *,
    company_id: uuid.UUID,
    customer_id: uuid.UUID,
    invoice_type: InvoiceType,
    description: str,
    net_amount: Decimal,
    contract_id: uuid.UUID | None = None,
    excess_usage_record_id: uuid.UUID | None = None,
    cost_sgd: Decimal | None = None,
) -> Invoice:
    """Shared construction: numbering, GST, and due date."""
    issued_on = date.today()
    tax_code, gst_rate, gst_amount, total = apply_gst(
        db, company_id=company_id, net_amount=Decimal(net_amount)
    )
    return Invoice(
        company_id=company_id,
        customer_id=customer_id,
        contract_id=contract_id,
        excess_usage_record_id=excess_usage_record_id,
        invoice_number=next_document_number(db, company_id=company_id, doc_kind="invoice"),
        invoice_type=invoice_type,
        description=description,
        amount_sgd=Decimal(net_amount),
        tax_code=tax_code,
        gst_rate=gst_rate,
        gst_amount_sgd=gst_amount,
        total_amount_sgd=total,
        due_date=_due_date_for(db, customer_id, issued_on),
        cost_sgd=cost_sgd,
    )


def issue_contract_annual_invoice(
    db: Session, contract: Contract, *, actor_user_id: uuid.UUID
) -> Invoice:
    """BILL-001: full 12-month contract value, billed at contract
    start/renewal. BILL-002: no approval required -- issued directly."""
    invoice = _build_invoice(
        db,
        company_id=contract.company_id,
        customer_id=contract.customer_id,
        invoice_type=InvoiceType.CONTRACT_ANNUAL,
        description=(
            f"Annual service contract ({contract.start_date.isoformat()} to "
            f"{contract.end_date.isoformat()})"
        ),
        net_amount=Decimal(contract.contract_value_sgd),
        contract_id=contract.id,
        cost_sgd=_cost_basis_for_contract(db, contract),
    )
    db.add(invoice)
    db.flush()

    # ACC-001/003 + BILL-005: issuing the invoice is the accounting event
    # (revenue recognised on invoice) -- post it now: Dr AR / Cr revenue /
    # Cr GST output. See app/services/posting.py.
    posting.post_invoice(db, invoice, actor_user_id=actor_user_id)

    audit.record(
        db,
        entity_type="invoice",
        entity_id=invoice.id,
        action="issued",
        actor_user_id=actor_user_id,
        details=(
            f"{invoice.invoice_number}, invoice_type=contract_annual, "
            f"contract_id={contract.id}"
        ),
        new_value={
            "invoice_number": invoice.invoice_number,
            "net_sgd": str(invoice.amount_sgd),
            "gst_sgd": str(invoice.gst_amount_sgd),
            "total_sgd": str(invoice.total_amount_sgd),
            "cost_sgd": str(invoice.cost_sgd) if invoice.cost_sgd is not None else None,
        },
    )
    return invoice


def blended_rate_per_hour(contract: Contract) -> Decimal:
    """SRV-008: contract value divided by contracted hours."""
    contracted_hours = Decimal(contract.contracted_minutes) / Decimal(60)
    return (Decimal(contract.contract_value_sgd) / contracted_hours).quantize(
        Decimal("0.01"), rounding=ROUND_HALF_UP
    )


def issue_excess_usage_invoice(
    db: Session,
    excess_record: ExcessUsageRecord,
    contract: Contract,
    *,
    actor_user_id: uuid.UUID,
) -> Invoice:
    """SRV-008: billable excess usage is charged at the contract's own
    blended rate, no customer pre-approval required."""
    rate = blended_rate_per_hour(contract)
    excess_hours = Decimal(excess_record.excess_minutes) / Decimal(60)
    amount = (rate * excess_hours).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)

    invoice = _build_invoice(
        db,
        company_id=contract.company_id,
        customer_id=contract.customer_id,
        invoice_type=InvoiceType.EXCESS_USAGE,
        description=(
            f"Excess support usage beyond contracted hours "
            f"({excess_hours} hrs @ SGD {rate}/hr)"
        ),
        net_amount=amount,
        contract_id=contract.id,
        excess_usage_record_id=excess_record.id,
    )
    db.add(invoice)
    excess_record.invoiced = True
    db.flush()

    # ACC-001/003 + BILL-005: post on issue -- Dr AR / Cr 4010 excess
    # usage revenue / Cr GST output.
    posting.post_invoice(db, invoice, actor_user_id=actor_user_id)

    audit.record(
        db,
        entity_type="invoice",
        entity_id=invoice.id,
        action="issued",
        actor_user_id=actor_user_id,
        details=(
            f"{invoice.invoice_number}, invoice_type=excess_usage, "
            f"excess_usage_record_id={excess_record.id}"
        ),
        new_value={
            "invoice_number": invoice.invoice_number,
            "net_sgd": str(invoice.amount_sgd),
            "gst_sgd": str(invoice.gst_amount_sgd),
            "total_sgd": str(invoice.total_amount_sgd),
        },
    )
    return invoice
