"""
Advertisements managed in Central Command and pushed to client DBs.

An advertisement (announcement + video URL) is created once in Central
Command, then assigned to specific clients.  The push service writes
the data into each assigned client's `announcements` and
`ad_banner_settings` tables.
"""
import uuid
from datetime import datetime

from sqlalchemy import Boolean, DateTime, ForeignKey, Integer, String, Text, func
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base


class Advertisement(Base):
    """A master announcement record managed in Central Command."""

    __tablename__ = "advertisements"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    tag: Mapped[str | None] = mapped_column(String(30), nullable=True)
    text: Mapped[str] = mapped_column(Text, nullable=False)
    sort_order: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    is_active: Mapped[bool] = mapped_column(Boolean, nullable=False, default=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )

    # Which clients should see this ad
    assignments: Mapped[list["AdAssignment"]] = relationship(
        back_populates="advertisement", cascade="all, delete-orphan"
    )


class AdAssignment(Base):
    """Links an advertisement to a specific client for targeting."""

    __tablename__ = "ad_assignments"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    advertisement_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("advertisements.id", ondelete="CASCADE"), nullable=False
    )
    client_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("clients.id", ondelete="CASCADE"), nullable=False
    )
    pushed_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )

    advertisement: Mapped["Advertisement"] = relationship(back_populates="assignments")
    client: Mapped["Client"] = relationship()


class VideoSetting(Base):
    """Master video URL setting pushed to client ad_banner_settings."""

    __tablename__ = "video_settings"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    video_url: Mapped[str | None] = mapped_column(String(1000), nullable=True)
    label: Mapped[str] = mapped_column(String(200), nullable=False, default="Default")
    is_active: Mapped[bool] = mapped_column(Boolean, nullable=False, default=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )


class VideoAssignment(Base):
    """Links a video setting to a specific client."""

    __tablename__ = "video_assignments"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    video_setting_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("video_settings.id", ondelete="CASCADE"), nullable=False
    )
    client_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("clients.id", ondelete="CASCADE"), nullable=False
    )
    pushed_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )

    video_setting: Mapped["VideoSetting"] = relationship()
    client: Mapped["Client"] = relationship()


# Import Client so relationship() can resolve
from app.models.clients import Client  # noqa: E402
