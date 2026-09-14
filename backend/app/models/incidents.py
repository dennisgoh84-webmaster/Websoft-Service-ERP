"""Incident Module (2026-09-12, docs/planned-work.md #2 -> now built,
see docs/open-business-decisions.md #36 for the confirmed rules).

An Incident is the front door for an incoming call or email before it
becomes real work: Support Staff (or the Outlook Add-in, on Dennis's
behalf) logs it, then routes it to one of a Sales Quotation, a Job
Order, or a Software Task -- each conversion auto-creates that real
record, pre-filled from the Incident, with a back-reference here
(confirmed 2026-09-11: this is not just an assignment/routing flag,
the target record actually gets created). An Incident that turns out to
need someone to just call back doesn't spin up a separate task record
-- it's a status + assignee on the Incident itself (confirmed
2026-09-12).

Lives under the existing "service_operations" module (already labelled
"Helpdesk / Service Operations (Job Orders)" in the module catalog --
see scripts/seed_demo.py's MODULE_CATALOG) rather than a new module key,
since an Incident is exactly the helpdesk front door for that same area.
"""
import enum
import uuid
from datetime import datetime

from sqlalchemy import DateTime, Enum, ForeignKey, String, Text, func
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base


class IncidentSource(str, enum.Enum):
    PHONE = "phone"
    EMAIL = "email"
    OTHER = "other"
    PORTAL = "portal"  # raised by a customer through the Helpdesk Portal (PORTAL-002)


class IncidentStatus(str, enum.Enum):
    OPEN = "open"  # just logged, not yet routed
    PENDING_CALLBACK = "pending_callback"  # assigned to call the customer back
    CONVERTED = "converted"  # routed to a Quotation/Job Order/Software Task
    CLOSED = "closed"  # resolved without needing to convert to anything


class Incident(Base):
    __tablename__ = "incidents"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    incident_number: Mapped[str] = mapped_column(String(50), nullable=False, index=True)

    # Nullable: an incoming call/email doesn't always identify a
    # matched Company/Individual immediately -- see
    # app/services/incidents.py's try_match_customer_by_email for the
    # one automatic match this system attempts (against Contact.email).
    # A Quotation/Job Order conversion requires this to be set first.
    customer_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("company_individuals.id"), nullable=True
    )

    source: Mapped[IncidentSource] = mapped_column(
        Enum(IncidentSource, name="incident_source"), nullable=False, default=IncidentSource.PHONE
    )
    subject: Mapped[str] = mapped_column(String(255), nullable=False)
    description: Mapped[str | None] = mapped_column(Text, nullable=True)

    # Captured as given, independent of whether customer_id got matched
    # -- especially useful for an Outlook-sourced Incident, where this
    # is exactly what the email itself carries.
    sender_name: Mapped[str | None] = mapped_column(String(255), nullable=True)
    sender_email: Mapped[str | None] = mapped_column(String(255), nullable=True)
    sender_phone: Mapped[str | None] = mapped_column(String(50), nullable=True)

    status: Mapped[IncidentStatus] = mapped_column(
        Enum(IncidentStatus, name="incident_status"), nullable=False, default=IncidentStatus.OPEN
    )
    # Set together when status becomes PENDING_CALLBACK (confirmed
    # 2026-09-12: no separate reminder/task record).
    assigned_to_user_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    # Set when a customer raised this through the Helpdesk Portal: the
    # person, not just the customer (PORTAL-001 audit trail).
    raised_by_portal_user_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("portal_users.id"), nullable=True
    )

    # Exactly one of these is set once status = CONVERTED -- which
    # target type this Incident was routed to.
    converted_quotation_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("quotations.id"), nullable=True
    )
    converted_job_order_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("job_orders.id"), nullable=True
    )
    converted_software_task_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("software_tasks.id"), nullable=True
    )

    close_reason: Mapped[str | None] = mapped_column(String(500), nullable=True)
    closed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    closed_by_user_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("users.id"), nullable=True)

    created_by_user_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())

    customer: Mapped["CompanyIndividual | None"] = relationship()  # noqa: F821
