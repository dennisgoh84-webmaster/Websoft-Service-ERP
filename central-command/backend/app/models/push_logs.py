"""
General push activity log — records every push operation Central
Command makes to a client database (ads, licenses, config updates).
"""
import enum
import uuid
from datetime import datetime

from sqlalchemy import DateTime, Enum, ForeignKey, String, Text, func
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base


class PushType(str, enum.Enum):
    ADVERTISEMENT = "advertisement"
    VIDEO = "video"
    LICENSE = "license"
    CONFIG = "config"
    VERSION = "version"
    SUPPORT_LOGIN = "support_login"


class PushLog(Base):
    """One log entry per push operation to a client DB."""

    __tablename__ = "push_logs"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    client_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("clients.id", ondelete="CASCADE"), nullable=False
    )
    push_type: Mapped[PushType] = mapped_column(
        Enum(PushType, name="push_type"), nullable=False
    )
    detail: Mapped[str] = mapped_column(String(500), nullable=False)
    success: Mapped[bool] = mapped_column(default=True)
    error_message: Mapped[str | None] = mapped_column(Text, nullable=True)
    pushed_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )
    pushed_by: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("admin_users.id"), nullable=True
    )

    client: Mapped["Client"] = relationship()


from app.models.clients import Client  # noqa: E402
