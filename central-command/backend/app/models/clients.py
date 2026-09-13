"""
Client registry — one row per deployed ERP instance.

Central Command connects to each client's PostgreSQL to push ads,
manage licenses, and push config updates.  Connection credentials
are stored here (encrypted at rest in production via env-level
encryption or a secrets manager — not in Central Command's app layer).
"""
import enum
import uuid
from datetime import datetime

from sqlalchemy import Boolean, DateTime, Enum, Integer, String, Text, func
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base


class ClientStatus(str, enum.Enum):
    ACTIVE = "active"
    SUSPENDED = "suspended"
    DECOMMISSIONED = "decommissioned"


class Client(Base):
    """One deployed ERP instance (one Docker stack + one PostgreSQL)."""

    __tablename__ = "clients"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    name: Mapped[str] = mapped_column(String(200), nullable=False)
    code: Mapped[str] = mapped_column(String(20), unique=True, nullable=False)

    # PostgreSQL connection details
    db_host: Mapped[str] = mapped_column(String(255), nullable=False)
    db_port: Mapped[int] = mapped_column(Integer, nullable=False, default=5432)
    db_name: Mapped[str] = mapped_column(String(100), nullable=False)
    db_username: Mapped[str] = mapped_column(String(100), nullable=False)
    db_password: Mapped[str] = mapped_column(String(500), nullable=False)
    db_use_tls: Mapped[bool] = mapped_column(Boolean, nullable=False, default=True)

    status: Mapped[ClientStatus] = mapped_column(
        Enum(ClientStatus, name="client_status"), default=ClientStatus.ACTIVE
    )
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)

    # Concurrent login / license cap (null = unlimited)
    max_licenses: Mapped[int | None] = mapped_column(
        Integer, nullable=True, default=None
    )

    # Last time Central Command successfully connected
    last_connected_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )
    # Alembic version head read from client DB
    last_known_alembic_head: Mapped[str | None] = mapped_column(
        String(50), nullable=True
    )

    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )
