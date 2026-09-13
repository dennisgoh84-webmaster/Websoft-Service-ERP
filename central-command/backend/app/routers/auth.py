"""Auth endpoints for Central Command admin login.

Login flow (2026-09-13):
1. POST /login — validates credentials, generates 6-digit OTP,
   "sends" it to the admin's registered email.  In dev mode (no SMTP)
   the OTP is returned in the response body so the flow can still be
   tested without email infrastructure.
2. POST /verify-otp — validates the OTP and returns a JWT.

Recovery flows:
- POST /forgot-password — sends a reset OTP to the admin's email
- POST /reset-password — validates the OTP and sets a new password
- POST /forgot-username — sends the username to the admin's email
"""
import logging
import secrets
from datetime import datetime, timedelta, timezone

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.orm import Session

from app.core.config import settings
from app.core.database import get_db
from app.core.deps import get_current_admin
from app.models.admin import AdminUser
from app.models.login_otp import LoginOTP
from app.schemas import (
    AdminUserOut,
    ForgotPasswordRequest,
    ForgotUsernameRequest,
    LoginRequest,
    ResetPasswordRequest,
    VerifyOTPRequest,
)
from app.services.auth import create_access_token, hash_password, verify_password

log = logging.getLogger(__name__)

router = APIRouter(prefix="/api/auth", tags=["auth"])

OTP_EXPIRY_MINUTES = 5


def _generate_otp() -> str:
    """Generate a 6-digit numeric OTP."""
    return f"{secrets.randbelow(1_000_000):06d}"


def _create_otp(db: Session, user: AdminUser, purpose: str = "login") -> LoginOTP:
    """Create and persist an OTP record."""
    otp = LoginOTP(
        admin_user_id=user.id,
        otp_code=_generate_otp(),
        purpose=purpose,
        expires_at=datetime.now(timezone.utc) + timedelta(minutes=OTP_EXPIRY_MINUTES),
    )
    db.add(otp)
    db.commit()
    db.refresh(otp)
    return otp


def _send_otp_email(user: AdminUser, otp_code: str, purpose: str) -> bool:
    """Send OTP via email.  Returns True if sent, False if no SMTP configured.

    In this development environment SMTP is not available, so the OTP
    is logged to the console instead.  Production would integrate a
    real mailer here.
    """
    if purpose == "login":
        subject = "Central Command Login OTP"
        body = f"Your login OTP is: {otp_code}\nValid for {OTP_EXPIRY_MINUTES} minutes."
    elif purpose == "reset_password":
        subject = "Central Command Password Reset OTP"
        body = f"Your password reset OTP is: {otp_code}\nValid for {OTP_EXPIRY_MINUTES} minutes."
    else:
        subject = "Central Command OTP"
        body = f"Your OTP is: {otp_code}"

    # Dev mode: log instead of sending
    log.info(
        "📧 [DEV] Email to %s (%s): %s — OTP: %s",
        user.email or "(no email)",
        user.full_name,
        subject,
        otp_code,
    )
    return False  # no actual email sent


def _send_username_email(user: AdminUser) -> bool:
    """Send username recovery email.  Dev mode: logged to console."""
    log.info(
        "📧 [DEV] Username recovery email to %s: Your username is '%s'",
        user.email,
        user.username,
    )
    return False


@router.post("/login")
def login(body: LoginRequest, db: Session = Depends(get_db)):
    user = db.query(AdminUser).filter(AdminUser.username == body.username).first()
    if not user or not verify_password(body.password, user.hashed_password):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Bad credentials")
    if not user.is_active:
        raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="Account disabled")

    # Generate login OTP
    otp = _create_otp(db, user, purpose="login")
    email_sent = _send_otp_email(user, otp.otp_code, "login")

    response: dict = {
        "status": "otp_required",
        "otp_session": str(otp.id),
        "email_sent": email_sent,
        "email_hint": _mask_email(user.email) if user.email else None,
    }
    # Dev mode: include OTP in response so testing works without email
    if not email_sent:
        response["_dev_otp"] = otp.otp_code

    return response


@router.post("/verify-otp")
def verify_otp(body: VerifyOTPRequest, db: Session = Depends(get_db)):
    otp = db.get(LoginOTP, body.otp_session)
    if not otp:
        raise HTTPException(status_code=400, detail="Invalid OTP session")
    if otp.is_used:
        raise HTTPException(status_code=400, detail="OTP already used")
    if otp.expires_at < datetime.now(timezone.utc):
        raise HTTPException(status_code=400, detail="OTP has expired")
    if otp.otp_code != body.otp_code:
        raise HTTPException(status_code=400, detail="Invalid OTP code")

    # Mark used
    otp.is_used = True
    db.commit()

    user = db.get(AdminUser, otp.admin_user_id)
    if not user or not user.is_active:
        raise HTTPException(status_code=403, detail="Account disabled")

    token = create_access_token(str(user.id))
    return {
        "status": "ok",
        "access_token": token,
        "token_type": "bearer",
        "full_name": user.full_name,
    }


@router.post("/forgot-password")
def forgot_password(body: ForgotPasswordRequest, db: Session = Depends(get_db)):
    """Send a password-reset OTP to the user's registered email."""
    user = db.query(AdminUser).filter(AdminUser.username == body.username).first()
    if not user:
        # Don't reveal whether the username exists
        return {"status": "ok", "message": "If the username exists, an OTP has been sent to the registered email."}
    if not user.email:
        raise HTTPException(status_code=400, detail="No email address registered for this account. Contact a super admin.")

    otp = _create_otp(db, user, purpose="reset_password")
    email_sent = _send_otp_email(user, otp.otp_code, "reset_password")

    response: dict = {
        "status": "ok",
        "message": "If the username exists, an OTP has been sent to the registered email.",
        "email_hint": _mask_email(user.email),
    }
    if not email_sent:
        response["_dev_otp"] = otp.otp_code
    return response


@router.post("/reset-password")
def reset_password(body: ResetPasswordRequest, db: Session = Depends(get_db)):
    """Reset password using username + OTP code."""
    user = db.query(AdminUser).filter(AdminUser.username == body.username).first()
    if not user:
        raise HTTPException(status_code=400, detail="Invalid request")

    # Find a valid, unused reset_password OTP for this user
    otp = (
        db.query(LoginOTP)
        .filter(
            LoginOTP.admin_user_id == user.id,
            LoginOTP.purpose == "reset_password",
            LoginOTP.is_used == False,  # noqa: E712
            LoginOTP.otp_code == body.otp_code,
            LoginOTP.expires_at > datetime.now(timezone.utc),
        )
        .first()
    )
    if not otp:
        raise HTTPException(status_code=400, detail="Invalid or expired OTP")

    # Validate new password (min 8 chars, alphanumeric)
    pwd = body.new_password
    if len(pwd) < 8:
        raise HTTPException(status_code=400, detail="Password must be at least 8 characters")
    if not any(c.isalpha() for c in pwd) or not any(c.isdigit() for c in pwd):
        raise HTTPException(status_code=400, detail="Password must contain both letters and numbers")

    # Update password
    user.hashed_password = hash_password(pwd)
    otp.is_used = True
    db.commit()

    return {"status": "ok", "message": "Password has been reset successfully. You can now log in."}


@router.post("/forgot-username")
def forgot_username(body: ForgotUsernameRequest, db: Session = Depends(get_db)):
    """Send the username to the registered email address."""
    user = db.query(AdminUser).filter(AdminUser.email == body.email).first()

    # Always return success to avoid email enumeration
    response: dict = {
        "status": "ok",
        "message": "If the email is registered, the username has been sent to it.",
    }

    if user:
        email_sent = _send_username_email(user)
        if not email_sent:
            response["_dev_username"] = user.username

    return response


@router.get("/me", response_model=AdminUserOut)
def me(admin: AdminUser = Depends(get_current_admin)):
    return admin


def _mask_email(email: str | None) -> str | None:
    """Mask email for display: 'admin@webmaster.com.sg' -> 'a****@w******.com.sg'"""
    if not email or "@" not in email:
        return None
    local, domain = email.split("@", 1)
    masked_local = local[0] + "****" if len(local) > 1 else local
    parts = domain.split(".")
    if len(parts) >= 2:
        masked_domain = parts[0][0] + "******" + "." + ".".join(parts[1:])
    else:
        masked_domain = domain[0] + "******"
    return f"{masked_local}@{masked_domain}"
