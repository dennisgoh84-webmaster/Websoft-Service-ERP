"""Incident Module business logic (2026-09-12, see
app/models/incidents.py's module docstring and
docs/open-business-decisions.md #36 for the confirmed rules this
implements). Kept as plain functions -- shared by app/routers/incidents.py
(the in-app screen) and the Outlook Add-in's from-email endpoints,
so both paths convert an Incident the exact same way.
"""
import uuid
from datetime import date, datetime, timezone
from decimal import Decimal

from sqlalchemy import func
from sqlalchemy.orm import Session

from app.models.company_individuals import CompanyIndividual, Contact
from app.models.contracts import Contract, ContractStatus
from app.models.incidents import Incident, IncidentSource, IncidentStatus
from app.models.job_orders import JobOrder, JobOrderPriority
from app.models.quotations import Quotation, QuotationLine
from app.models.software_tasks import SoftwareTask
from app.services import audit
from app.services.numbering import next_document_number

# A Job Order can be raised against a contract that's still within its
# term even if its hours are used up (SRV-001/SRV-008's excess-usage
# path exists for exactly that) -- so "valid" here means ACTIVE or
# EXCEEDED, not DRAFT (never activated), EXPIRED, or RENEWED
# (superseded by a newer contract).
VALID_CONTRACT_STATUSES = (ContractStatus.ACTIVE, ContractStatus.EXCEEDED)


class IncidentRuleViolation(Exception):
    pass


def try_match_customer_by_email(db: Session, company_id: uuid.UUID, email: str | None) -> uuid.UUID | None:
    """The one automatic customer match this system attempts: a case-
    insensitive match against an existing Contact's email address on a
    Company/Individual belonging to this company. No match -> None,
    left for a human to set (see IncidentSetCustomer) -- never guessed
    at more aggressively than an exact email match."""
    if not email:
        return None
    contact = (
        db.query(Contact)
        .join(CompanyIndividual, Contact.customer_id == CompanyIndividual.id)
        .filter(CompanyIndividual.company_id == company_id, func.lower(Contact.email) == email.lower())
        .first()
    )
    return contact.customer_id if contact else None


def find_valid_contract(db: Session, company_id: uuid.UUID, customer_id: uuid.UUID) -> Contract | None:
    """Most-recently-started contract still valid for raising a new Job
    Order against (see VALID_CONTRACT_STATUSES above). None if the
    customer has no such contract."""
    return (
        db.query(Contract)
        .filter(
            Contract.company_id == company_id,
            Contract.customer_id == customer_id,
            Contract.status.in_(VALID_CONTRACT_STATUSES),
        )
        .order_by(Contract.start_date.desc())
        .first()
    )


def create_incident(
    db: Session,
    *,
    company_id: uuid.UUID,
    customer_id: uuid.UUID | None,
    source: IncidentSource,
    subject: str,
    description: str | None,
    sender_name: str | None,
    sender_email: str | None,
    sender_phone: str | None,
    created_by_user_id: uuid.UUID | None,
    raised_by_portal_user_id: uuid.UUID | None = None,
    portal_actor_name: str | None = None,
) -> Incident:
    """`raised_by_portal_user_id`/`portal_actor_name` are set only when a
    customer raised this themselves through the Helpdesk Portal
    (PORTAL-002, app/routers/portal.py) -- `created_by_user_id` stays
    None in that case (no staff member created it), so the audit trail
    is told the actor's name directly rather than looking one up on
    `User` (see app/services/audit.py's docstring on those two params)."""
    incident = Incident(
        company_id=company_id,
        incident_number=next_document_number(db, company_id=company_id, doc_kind="incident"),
        customer_id=customer_id,
        source=source,
        subject=subject,
        description=description,
        sender_name=sender_name,
        sender_email=sender_email,
        sender_phone=sender_phone,
        created_by_user_id=created_by_user_id,
        raised_by_portal_user_id=raised_by_portal_user_id,
    )
    db.add(incident)
    db.flush()
    audit.record(
        db,
        entity_type="incident",
        entity_id=incident.id,
        action="created",
        actor_user_id=created_by_user_id,
        actor_name=portal_actor_name,
        company_id=company_id if raised_by_portal_user_id else None,
        details=f"{incident.incident_number}: {subject}",
        new_value={"source": source.value, "customer_id": str(customer_id) if customer_id else None},
    )
    return incident


def _require_open(incident: Incident) -> None:
    if incident.status != IncidentStatus.OPEN:
        raise IncidentRuleViolation(
            f"Incident {incident.incident_number} is already {incident.status.value}, not open."
        )


def set_callback(db: Session, incident: Incident, *, assigned_to_user_id: uuid.UUID, actor_user_id: uuid.UUID | None) -> Incident:
    _require_open(incident)
    incident.status = IncidentStatus.PENDING_CALLBACK
    incident.assigned_to_user_id = assigned_to_user_id
    audit.record(
        db, entity_type="incident", entity_id=incident.id, action="pending_callback",
        actor_user_id=actor_user_id, new_value={"assigned_to_user_id": str(assigned_to_user_id)},
    )
    return incident


def close_incident(db: Session, incident: Incident, *, reason: str, actor_user_id: uuid.UUID | None) -> Incident:
    if incident.status == IncidentStatus.CONVERTED:
        raise IncidentRuleViolation(f"Incident {incident.incident_number} was already converted.")
    if incident.status == IncidentStatus.CLOSED:
        raise IncidentRuleViolation(f"Incident {incident.incident_number} is already closed.")
    incident.status = IncidentStatus.CLOSED
    incident.close_reason = reason
    incident.closed_at = datetime.now(timezone.utc)
    incident.closed_by_user_id = actor_user_id
    audit.record(
        db, entity_type="incident", entity_id=incident.id, action="closed",
        actor_user_id=actor_user_id, new_value={"close_reason": reason},
    )
    return incident


def convert_to_quotation(
    db: Session, incident: Incident, *, quotation_date: date, actor_user_id: uuid.UUID | None
) -> Quotation:
    _require_open(incident)
    if incident.customer_id is None:
        raise IncidentRuleViolation("Set a Company/Individual on this Incident before converting to a Quotation.")

    quotation = Quotation(
        company_id=incident.company_id,
        quotation_number=next_document_number(db, company_id=incident.company_id, doc_kind="quotation"),
        customer_id=incident.customer_id,
        quotation_date=quotation_date,
        notes=f"From Incident {incident.incident_number}: {incident.subject}",
        created_by_user_id=actor_user_id,
    )
    db.add(quotation)
    db.flush()
    # A Quotation needs at least one line -- a placeholder Sales can
    # price properly, since an Incident only carries a subject/description,
    # never product/price detail.
    db.add(
        QuotationLine(
            quotation_id=quotation.id,
            description=incident.subject,
            quantity=Decimal("1"),
            unit_price_sgd=Decimal("0.00"),
            line_total_sgd=Decimal("0.00"),
        )
    )
    incident.status = IncidentStatus.CONVERTED
    incident.converted_quotation_id = quotation.id
    audit.record(
        db, entity_type="incident", entity_id=incident.id, action="converted_to_quotation",
        actor_user_id=actor_user_id, new_value={"quotation_id": str(quotation.id)},
    )
    return quotation


def convert_to_job_order(
    db: Session, incident: Incident, *, contract_id: uuid.UUID, priority: JobOrderPriority, actor_user_id: uuid.UUID | None
) -> JobOrder:
    _require_open(incident)
    if incident.customer_id is None:
        raise IncidentRuleViolation("Set a Company/Individual on this Incident before converting to a Job Order.")
    contract = db.get(Contract, contract_id)
    if contract is None or contract.company_id != incident.company_id or contract.customer_id != incident.customer_id:
        raise IncidentRuleViolation("That contract doesn't belong to this Incident's Company/Individual.")
    if contract.status not in VALID_CONTRACT_STATUSES:
        raise IncidentRuleViolation(
            f"Contract {contract.contract_number} is {contract.status.value}, not a valid contract to raise a Job Order against."
        )

    job_order = JobOrder(
        company_id=incident.company_id,
        customer_id=incident.customer_id,
        contract_id=contract.id,
        job_order_number=next_document_number(db, company_id=incident.company_id, doc_kind="job_order"),
        subject=incident.subject,
        priority=priority,
    )
    db.add(job_order)
    db.flush()
    incident.status = IncidentStatus.CONVERTED
    incident.converted_job_order_id = job_order.id
    audit.record(
        db, entity_type="incident", entity_id=incident.id, action="converted_to_job_order",
        actor_user_id=actor_user_id, new_value={"job_order_id": str(job_order.id)},
    )
    return job_order


def convert_to_software_task(
    db: Session, incident: Incident, *, assigned_programmer_id: uuid.UUID | None, actor_user_id: uuid.UUID | None
) -> SoftwareTask:
    _require_open(incident)
    task = SoftwareTask(
        company_id=incident.company_id,
        title=incident.subject,
        description=incident.description,
        assigned_programmer_id=assigned_programmer_id,
        created_by_user_id=actor_user_id,
    )
    db.add(task)
    db.flush()
    incident.status = IncidentStatus.CONVERTED
    incident.converted_software_task_id = task.id
    audit.record(
        db, entity_type="incident", entity_id=incident.id, action="converted_to_software_task",
        actor_user_id=actor_user_id, new_value={"software_task_id": str(task.id)},
    )
    return task
