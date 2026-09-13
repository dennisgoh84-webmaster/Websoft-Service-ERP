"""
Version Control — track ERP releases and manage client upgrades.

Central Command maintains a registry of ERP versions (each tied to an
Alembic migration head). When a new version is published, admins can
push upgrades to client databases, running Alembic migrations remotely.
"""
import enum
import uuid
from datetime import datetime

from sqlalchemy import Boolean, DateTime, Enum, ForeignKey, Integer, String, Text, func
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base


class VersionStatus(str, enum.Enum):
    DRAFT = "draft"
    RELEASED = "released"
    DEPRECATED = "deprecated"


class ERPVersion(Base):
    """One published version of the Websoft Service ERP platform."""

    __tablename__ = "erp_versions"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    version_number: Mapped[str] = mapped_column(
        String(20), unique=True, nullable=False
    )  # e.g. "1.0.0", "1.1.0"
    alembic_head: Mapped[str] = mapped_column(
        String(50), nullable=False
    )  # The Alembic migration head for this version
    release_notes: Mapped[str | None] = mapped_column(Text, nullable=True)
    status: Mapped[VersionStatus] = mapped_column(
        Enum(VersionStatus, name="version_status"), default=VersionStatus.DRAFT
    )
    is_latest: Mapped[bool] = mapped_column(Boolean, default=False)
    released_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )
    created_by: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("admin_users.id"), nullable=True
    )


class ClientUpgradeLog(Base):
    """Records each upgrade push attempt to a client."""

    __tablename__ = "client_upgrade_logs"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    client_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("clients.id", ondelete="CASCADE"), nullable=False
    )
    from_version: Mapped[str | None] = mapped_column(String(50), nullable=True)
    to_version: Mapped[str] = mapped_column(String(20), nullable=False)
    to_alembic_head: Mapped[str] = mapped_column(String(50), nullable=False)
    success: Mapped[bool] = mapped_column(default=False)
    error_message: Mapped[str | None] = mapped_column(Text, nullable=True)
    upgraded_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )
    upgraded_by: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("admin_users.id"), nullable=True
    )

    client: Mapped["Client"] = relationship()


from app.models.clients import Client  # noqa: E402
