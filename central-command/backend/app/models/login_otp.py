"""
Login OTP codes for Central Command admin authentication.

After a successful username+password check, a 6-digit OTP is generated
and (in production) emailed to the admin's registered address.  The user
must present the OTP to receive a JWT.  In development mode (no SMTP
configured) the OTP is returned in the API response so the flow can
still be tested.

The same model is reused for forgot-password reset OTPs.
"""
import uuid
from datetime import datetime

from sqlalchemy import Boolean, DateTime, String, func
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base


class LoginOTP(Base):
    __tablename__ = "login_otps"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    admin_user_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), nullable=False)
    otp_code: Mapped[str] = mapped_column(String(10), nullable=False)
    purpose: Mapped[str] = mapped_column(
        String(30), nullable=False, default="login"
    )  # "login" | "reset_password"
    is_used: Mapped[bool] = mapped_column(Boolean, default=False)
    expires_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), nullable=False)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )
