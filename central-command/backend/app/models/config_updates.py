"""
Configuration updates pushed from Central Command to client DBs.

Covers things like tax rate changes, new default settings, or any
SQL-based configuration update that should propagate to all (or
selected) client instances.
"""
import enum
import uuid
from datetime import datetime

from sqlalchemy import DateTime, Enum, ForeignKey, String, Text, func
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base


class ConfigUpdateStatus(str, enum.Enum):
    DRAFT = "draft"
    READY = "ready"        # reviewed, ready to push
    PUSHED = "pushed"      # successfully pushed to all targeted clients
    PARTIAL = "partial"    # pushed to some clients, failed on others


class ConfigUpdate(Base):
    """A named configuration change to push to client databases.

    The `sql_statement` is a parameterised SQL string (never user input)
    written by the Central Command admin.  It targets the client-side
    schema (e.g. updating a tax rate, inserting a new default setting).
    """

    __tablename__ = "config_updates"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    title: Mapped[str] = mapped_column(String(200), nullable=False)
    description: Mapped[str | None] = mapped_column(Text, nullable=True)
    sql_statement: Mapped[str] = mapped_column(Text, nullable=False)
    status: Mapped[ConfigUpdateStatus] = mapped_column(
        Enum(ConfigUpdateStatus, name="config_update_status"),
        default=ConfigUpdateStatus.DRAFT,
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )

    push_logs: Mapped[list["ConfigPushLog"]] = relationship(
        back_populates="config_update", cascade="all, delete-orphan"
    )


class ConfigPushLog(Base):
    """Tracks the result of pushing a config update to each client."""

    __tablename__ = "config_push_logs"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    config_update_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("config_updates.id", ondelete="CASCADE"), nullable=False
    )
    client_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("clients.id", ondelete="CASCADE"), nullable=False
    )
    success: Mapped[bool] = mapped_column(default=False)
    error_message: Mapped[str | None] = mapped_column(Text, nullable=True)
    pushed_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )

    config_update: Mapped["ConfigUpdate"] = relationship(back_populates="push_logs")
    client: Mapped["Client"] = relationship()


from app.models.clients import Client  # noqa: E402
