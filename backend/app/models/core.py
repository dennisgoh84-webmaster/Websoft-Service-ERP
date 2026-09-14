"""
Core / Administration models: Company, User, and the central Audit Log.

RBAC is split across two independent axes (confirmed with Dennis,
2026-09-10 -- resolves open item 8.4):
- `User.role` is a small fixed enum used ONLY for the specific
  named-responsibility rules already confirmed in the business rules
  (e.g. SRV-004/SRV-011: Nico, or Cherish as backup, decides excess
  usage; Dennis as owner). It does not drive general module access.
- Group Authority (see app/models/groups.py) drives general per-module
  security: what a user can see/do in each module, at None/View/Edit/
  Full granularity, controlled by which Group they belong to. The group
  assignment lives on `UserCompanyAccess`, not on the user: exactly one
  Group **per company** the user works in (confirmed 2026-09-10), since
  Groups are themselves company-scoped.
"""
import enum
import uuid
from datetime import datetime
from decimal import Decimal

from sqlalchemy import DateTime, Enum, ForeignKey, Integer, Numeric, String, Text, UniqueConstraint, func
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base


class UserRole(str, enum.Enum):
    OWNER = "owner"  # Dennis
    SERVICE_LEAD = "service_lead"  # Nico
    SALES_MANAGER = "sales_manager"  # Cherish
    SUPPORT_ENGINEER = "support_engineer"
    FINANCE = "finance"


class Company(Base):
    """A legal entity using the system, managed from Company Setup
    (app/routers/companies.py). Webmaster Consultancy Pte Ltd is the
    first; CLAUDE.md's approved architecture anticipates more, so every
    company-owned record (customers, contracts, job orders, groups,
    users, audit entries) carries a `company_id` and is filtered by the
    signed-in user's *active* company.

    `logo` holds a small image as a data URI (e.g.
    "data:image/png;base64,...") shown at the top-left of the app.
    Business *documents* (contracts, invoices, attachments) still belong
    in external file storage per docs/system-architecture.md -- a logo
    is UI branding configuration, a few KB, needed on every page render,
    so it is deliberately kept inline rather than standing up file
    storage infrastructure for it."""

    __tablename__ = "companies"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    name: Mapped[str] = mapped_column(String(255), nullable=False)
    country: Mapped[str] = mapped_column(String(100), default="Singapore")
    currency: Mapped[str] = mapped_column(String(3), default="SGD")
    timezone: Mapped[str] = mapped_column(String(50), default="Asia/Singapore")
    logo: Mapped[str | None] = mapped_column(Text, nullable=True)
    # A Singapore tax invoice must show the supplier's name, address and
    # GST registration number, so they live on the company record.
    address: Mapped[str | None] = mapped_column(Text, nullable=True)
    gst_registration_no: Mapped[str | None] = mapped_column(String(50), nullable=True)
    # Confirmed 2026-09-10, from Webmaster's own Quotation letterhead
    # (reference: Quote_0160): shown on every printed form's header.
    phone: Mapped[str | None] = mapped_column(String(100), nullable=True)
    website: Mapped[str | None] = mapped_column(String(255), nullable=True)
    # ACRA business registration number ("Business Reg#" on the
    # reference letterhead) -- distinct from gst_registration_no even
    # though the two happen to match for a GST-registered sole entity.
    uen: Mapped[str | None] = mapped_column(String(50), nullable=True)

    # Approval thresholds. Each is DELIBERATELY nullable and unset: the
    # values were never decided (open items 2.7 / 3.4 / 4.4), so rather
    # than inventing a number the system requires owner approval for
    # every such action until Dennis sets one here.
    write_off_approval_threshold_sgd: Mapped[Decimal | None] = mapped_column(
        Numeric(12, 2), nullable=True
    )
    credit_note_approval_threshold_sgd: Mapped[Decimal | None] = mapped_column(
        Numeric(12, 2), nullable=True
    )
    po_approval_threshold_sgd: Mapped[Decimal | None] = mapped_column(
        Numeric(12, 2), nullable=True
    )

    is_active: Mapped[bool] = mapped_column(default=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


class UserCompanyAccess(Base):
    """Which companies a staff member may work in, and their Group in
    each one (multi-company).

    Separate from `User.company_id`, which is the company they are
    *currently* working in: one row here per company they are *allowed*
    to switch to. A staff member with a single row (the normal case)
    never sees the company switcher; someone like Dennis, who owns more
    than one entity, gets a row per company and switches between them.
    Switching only rewrites `User.company_id`, so every existing
    company-scoped query keeps working untouched.

    `group_id` is the Group Authority group that applies to this person
    **in this company** (confirmed with Dennis, 2026-09-10: a Group per
    company, not one global Group). Groups are themselves company-scoped,
    so someone working across two entities can be, say, Finance in one
    and Owner / Admin in the other. Null means no group there, which
    resolves to no access (the owner role still overrides everything --
    see app/services/authority.py)."""

    __tablename__ = "user_company_access"
    __table_args__ = (UniqueConstraint("user_id", "company_id", name="uq_user_company"),)

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    user_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("users.id"), nullable=False)
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    group_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("groups.id"), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())

    company: Mapped["Company"] = relationship()
    group: Mapped["Group | None"] = relationship()  # noqa: F821


class User(Base):
    __tablename__ = "users"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    # The company this user is *currently* working in. Everything they
    # see is scoped to it. Multi-company: UserCompanyAccess lists the
    # companies they may switch to, and switching rewrites this field.
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    email: Mapped[str] = mapped_column(String(255), unique=True, nullable=False)
    hashed_password: Mapped[str] = mapped_column(String(255), nullable=False)
    full_name: Mapped[str] = mapped_column(String(255), nullable=False)
    role: Mapped[UserRole] = mapped_column(Enum(UserRole, name="user_role"), nullable=False)
    # Group Authority is NOT here: the group is per company, so it lives
    # on UserCompanyAccess.group_id (one Group per company this user
    # works in). `role` above stays global -- it only drives the
    # named-responsibility business rules, not module access.
    # Confirmed 2026-09-11: Support Monitoring should be able to show a
    # staff photo. Same inline-data-URI pattern as Company.logo above,
    # for the same reason -- a small image needed on every render of a
    # staff-facing screen, not a business document.
    photo: Mapped[str | None] = mapped_column(Text, nullable=True)
    # Confirmed 2026-09-12: "New staff user for the first time to force
    # them change own password" -- True for every newly created user
    # (including via seed_demo.py) and after an admin password reset;
    # cleared once they complete POST /api/auth/change-password. See
    # app/routers/auth.py's login sequence.
    must_change_password: Mapped[bool] = mapped_column(default=True)
    is_active: Mapped[bool] = mapped_column(default=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())

    company: Mapped["Company"] = relationship()
    company_access: Mapped[list["UserCompanyAccess"]] = relationship(
        primaryjoin="User.id == UserCompanyAccess.user_id", viewonly=True
    )


class LoginOtp(Base):
    """One issued email OTP challenge (2026-09-12: "enhance security with
    OTP upon login... email or handphone whatsapp"). Only email is built
    -- WhatsApp OTP needs an automated send-and-verify integration (a
    WhatsApp Business API account) this system doesn't have yet; see
    docs/planned-work.md. Issued only when SMTP is configured
    (app/services/mailer.is_configured) -- otherwise login skips the OTP
    step entirely rather than locking everyone out.

    `code_hash` is a SHA-256 hash, not the plaintext code -- a 6-digit
    OTP is far weaker than a real password, but there is no reason to
    store it recoverable either. `attempts` caps guesses at the code
    before the whole challenge must be restarted (a fresh login).

    `purpose` (2026-09-12, added for "forget password") distinguishes a
    login-time OTP from a forgot-password OTP -- the same row shape,
    but a code emailed for one must never be usable for the other (see
    app/routers/auth.py's login/verify-otp and forgot-password/reset-
    password-otp, each of which filters on its own purpose)."""

    __tablename__ = "login_otps"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    # Exactly one of user_id (staff) / portal_user_id (customer portal,
    # PORTAL-003) is set. One OTP table and one mailer path serve both
    # logins rather than duplicating the flow -- see
    # docs/customer-portal-design.md §3.
    user_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    portal_user_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("portal_users.id"), nullable=True
    )
    code_hash: Mapped[str] = mapped_column(String(64), nullable=False)
    purpose: Mapped[str] = mapped_column(String(20), nullable=False, default="login")
    attempts: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    expires_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), nullable=False)
    consumed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


class AuditLogEntry(Base):
    """Central audit trail -- the data behind the Event Logs module (see
    app/routers/event_logs.py). Every module writes here for actions
    that must be auditable per CLAUDE.md's development rules and,
    concretely, SRV-004's requirement that excess-usage decisions
    record who decided, when, what, and why.

    `actor_name`, `ip_address`, `user_agent`, and `device_id` are a
    point-in-time snapshot taken when the entry is written (see
    app/services/audit.py) so the trail stays meaningful even if the
    actor's name later changes or their account is deactivated. Note:
    a browser cannot expose a real hardware/PC serial number for
    security reasons -- `device_id` is a random identifier the
    frontend generates once and persists in that browser's storage
    (see frontend/src/lib/deviceId.ts), which identifies "this browser
    on this machine" rather than the physical hardware.

    `old_value`/`new_value` hold a small JSON object of just the
    fields that changed (e.g. '{"role": "support_engineer"}' ->
    '{"role": "service_lead"}') for edits where a field-level diff is
    meaningful; left null for actions where it isn't (e.g. a password
    reset never records the password itself)."""

    __tablename__ = "audit_log_entries"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    # The company the action happened in, so Event Logs shows each
    # company only its own trail (multi-company). Stamped automatically
    # from the actor's active company -- see app/services/audit.py.
    company_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("companies.id"), nullable=True)
    entity_type: Mapped[str] = mapped_column(String(100), nullable=False)
    entity_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), nullable=False)
    action: Mapped[str] = mapped_column(String(100), nullable=False)
    actor_user_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    actor_name: Mapped[str | None] = mapped_column(String(255), nullable=True)
    reason: Mapped[str | None] = mapped_column(Text, nullable=True)
    details: Mapped[str | None] = mapped_column(Text, nullable=True)
    old_value: Mapped[str | None] = mapped_column(Text, nullable=True)
    new_value: Mapped[str | None] = mapped_column(Text, nullable=True)
    ip_address: Mapped[str | None] = mapped_column(String(64), nullable=True)
    user_agent: Mapped[str | None] = mapped_column(String(500), nullable=True)
    device_id: Mapped[str | None] = mapped_column(String(255), nullable=True)
    at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
