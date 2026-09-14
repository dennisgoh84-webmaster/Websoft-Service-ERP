"""
Central audit logging helper -- every module writes through here rather
than each inventing its own logging, per the architecture in
docs/system-architecture.md (Audit logging section). This is the data
source for the Event Logs module (app/routers/event_logs.py).

Request-scoped context (who + which browser/device made the call) is
captured once per request by the middleware in app/main.py and read
back here via a contextvar, so callers don't need to thread a FastAPI
`Request` through every service function just to log an action -- they
only need to know *what* happened (entity, action, old/new values).
"""
import contextvars
import json
import uuid
from typing import Any

from sqlalchemy.orm import Session

from app.models.core import AuditLogEntry, User

_request_context: contextvars.ContextVar[dict[str, str | None]] = contextvars.ContextVar(
    "audit_request_context", default={}
)


def set_request_context(
    *, ip_address: str | None, user_agent: str | None, device_id: str | None
) -> None:
    """Called once per HTTP request (see the middleware in app/main.py)."""
    _request_context.set(
        {"ip_address": ip_address, "user_agent": user_agent, "device_id": device_id}
    )


def _to_json(value: dict[str, Any] | None) -> str | None:
    if value is None:
        return None
    return json.dumps(value, default=str, sort_keys=True)


def record(
    db: Session,
    *,
    entity_type: str,
    entity_id: uuid.UUID,
    action: str,
    actor_user_id: uuid.UUID | None,
    actor_name: str | None = None,
    company_id: uuid.UUID | None = None,
    reason: str | None = None,
    details: str | None = None,
    old_value: dict[str, Any] | None = None,
    new_value: dict[str, Any] | None = None,
) -> AuditLogEntry:
    """`actor_name`/`company_id` are only for a non-staff actor (the
    Customer Helpdesk Portal, PORTAL-001..004): a PortalUser isn't a
    `User` row, so there's nothing to look up here -- the caller passes
    them directly (e.g. the contact's name and their PortalUser.company_id)
    so the entry still shows a real name and lands in the right
    company's Event Logs. A staff actor_user_id always overrides both,
    same as before this parameter existed."""
    ctx = _request_context.get()
    if actor_user_id is not None:
        actor = db.get(User, actor_user_id)
        if actor is not None:
            actor_name = actor.full_name
            # The company the actor was working in when this happened,
            # so Event Logs can show each company only its own trail.
            company_id = actor.company_id

    entry = AuditLogEntry(
        company_id=company_id,
        entity_type=entity_type,
        entity_id=entity_id,
        action=action,
        actor_user_id=actor_user_id,
        actor_name=actor_name,
        reason=reason,
        details=details,
        old_value=_to_json(old_value),
        new_value=_to_json(new_value),
        ip_address=ctx.get("ip_address"),
        user_agent=ctx.get("user_agent"),
        device_id=ctx.get("device_id"),
    )
    db.add(entry)
    return entry


def record_report_generated(
    db: Session,
    *,
    actor_user_id: uuid.UUID | None,
    report_name: str,
    details: str | None = None,
) -> AuditLogEntry:
    """Log that a report/export was generated (e.g. the Event Logs CSV
    export). `entity_id` is a fresh UUID -- a generated report isn't a
    row that exists elsewhere to point back to, unlike other entries."""
    return record(
        db,
        entity_type="report",
        entity_id=uuid.uuid4(),
        action="report_generated",
        actor_user_id=actor_user_id,
        details=details or report_name,
        new_value={"report": report_name},
    )
