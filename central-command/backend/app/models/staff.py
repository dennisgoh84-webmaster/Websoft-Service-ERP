"""
Staff Management — Central Command's own staff and support-login push.

AdminUser (admin.py) is the simple existing admin table. This module
adds a richer staff model with roles, and tracks support logins
pushed to client ERP databases for technical support purposes.
"""
import enum
import uuid
from datetime import datetime

from sqlalchemy import Boolean, DateTime, Enum, ForeignKey, String, Text, func
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base


class StaffRole(str, enum.Enum):
    SUPER_ADMIN = "super_admin"      # Full access to everything
    ADMIN = "admin"                   # Can manage clients, push updates
    SUPPORT_ENGINEER = "support_engineer"  # Can view clients, push support logins
    VIEWER = "viewer"                 # Read-only access


class SupportLoginStatus(str, enum.Enum):
    ACTIVE = "active"
    REVOKED = "revoked"


class SupportLogin(Base):
    """Tracks support staff logins pushed to client ERP databases.

    When a CC admin pushes a support login to a client, we create a user
    in the client's `users` table and record it here. The login can later
    be revoked (disabled) remotely.
    """

    __tablename__ = "support_logins"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    # Which CC admin this support login is for
    admin_user_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("admin_users.id"), nullable=False
    )
    # Which client DB the login was pushed to
    client_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("clients.id", ondelete="CASCADE"), nullable=False
    )
    # The email used for the support login in the client DB
    login_email: Mapped[str] = mapped_column(String(255), nullable=False)
    # The user ID created in the client DB (for revoke operations)
    client_user_id: Mapped[str | None] = mapped_column(String(100), nullable=True)
    status: Mapped[SupportLoginStatus] = mapped_column(
        Enum(SupportLoginStatus, name="support_login_status"),
        default=SupportLoginStatus.ACTIVE,
    )
    reason: Mapped[str | None] = mapped_column(Text, nullable=True)
    pushed_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )
    pushed_by: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("admin_users.id"), nullable=True
    )
    revoked_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )

    admin_user: Mapped["AdminUser"] = relationship(foreign_keys=[admin_user_id])
    client: Mapped["Client"] = relationship()


from app.models.admin import AdminUser  # noqa: E402
from app.models.clients import Client  # noqa: E402
