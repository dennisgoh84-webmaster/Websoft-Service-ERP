"""
Central Command admin users.

These are Web Master Consultancy staff who operate Central Command,
NOT end-users of any client ERP.  Very small table: Dennis + a handful
of IT staff.

`role` controls what each staff member can do in Central Command:
- super_admin: full access, manage other staff, push anything
- admin: manage clients, push ads/licenses/config/versions
- support_engineer: view clients, push support logins for tech support
- viewer: read-only dashboard and client info
"""
import uuid
from datetime import datetime

from sqlalchemy import Boolean, DateTime, String, func
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base


class AdminUser(Base):
    __tablename__ = "admin_users"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    username: Mapped[str] = mapped_column(String(50), unique=True, nullable=False)
    full_name: Mapped[str] = mapped_column(String(200), nullable=False)
    email: Mapped[str | None] = mapped_column(String(255), nullable=True)
    hashed_password: Mapped[str] = mapped_column(String(200), nullable=False)
    role: Mapped[str] = mapped_column(
        String(30), nullable=False, default="admin"
    )  # super_admin / admin / support_engineer / viewer
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )
