"""
Customer Helpdesk Portal -- the customer-side login (PORTAL-001..004).

A PortalUser is one *person* at a customer -- exactly one per Contact
(PORTAL-001) -- and lives in its own table, never in staff `users`
(PORTAL-003). Its token carries purpose="portal" and staff endpoints
reject it; see app/core/deps.py get_current_portal_user, which is the
whole security boundary. Staff enable access from the Company/Individual
page (app/routers/portal_access.py); the customer signs in at /portal
(app/routers/portal.py).

Design: docs/customer-portal-design.md §3.
"""
import uuid
from datetime import datetime

from sqlalchemy import Boolean, DateTime, ForeignKey, Integer, String, UniqueConstraint, func
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base


class PortalUser(Base):
    __tablename__ = "portal_users"
    __table_args__ = (
        # One login per contact (PORTAL-001); one email per company so a
        # login attempt resolves to exactly one person.
        UniqueConstraint("contact_id", name="uq_portal_user_contact"),
        UniqueConstraint("company_id", "email", name="uq_portal_user_company_email"),
    )

    id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    contact_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("contacts.id"), nullable=False)
    # Copied from the Contact when access is enabled; the Contact's email
    # can change later without silently changing the login.
    email: Mapped[str] = mapped_column(String(255), nullable=False)
    hashed_password: Mapped[str] = mapped_column(String(255), nullable=False)
    is_active: Mapped[bool] = mapped_column(Boolean, nullable=False, default=True)
    # Invite flow: the temporary password must be replaced at first login.
    must_change_password: Mapped[bool] = mapped_column(Boolean, nullable=False, default=True)
    # 5 wrong passwords lock the login for 15 minutes (design §4).
    failed_attempts: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    locked_until: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    last_login_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    # The staff member who enabled access -- part of the audit trail.
    created_by_user_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())

    contact: Mapped["Contact"] = relationship()  # noqa: F821
    company: Mapped["Company"] = relationship()  # noqa: F821
