"""Customer Helpdesk Portal -- customer-side auth + data endpoints
(PORTAL-001..006). Design: docs/customer-portal-design.md.

Auth sequence (deliberately simpler than staff's -- see schemas.py's
Portal* docstring and design doc §4):
1. POST /api/portal/auth/login (email + password). Returns
   status="otp_required" (a code was just emailed) or status="ok" with
   the real portal_token straight away if SMTP isn't configured --
   same fail-open behaviour as staff login, for the same reason (never
   let a missing SMTP setup lock everyone out).
2. POST /api/portal/auth/verify-otp (otp_token + code). Returns
   status="ok" with portal_token -- a JWT carrying purpose="portal",
   the whole security boundary (see app/core/deps.py
   get_current_portal_user). must_change_password comes back alongside
   it; the frontend routes to the change-password screen first when
   true, but the token itself already works for every other endpoint.
3. POST /api/portal/auth/change-password (Authorization: Bearer
   <portal_token> + new_password). Clears must_change_password.

"Forgot password" mirrors staff's: POST /auth/forgot-password always
returns the same generic message; POST /auth/reset-password-otp sets a
new password directly once email+code match.

Lockout (design §4): 5 wrong passwords locks the login for 15 minutes,
tracked on PortalUser.failed_attempts / locked_until (there is no
separate table -- a portal login is one person, one row).
"""
import hashlib
import secrets
import uuid
from datetime import datetime, timedelta, timezone

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import get_current_portal_user
from app.models.billing import Invoice
from app.models.contracts import Contract
from app.models.core import LoginOtp, User
from app.models.incidents import Incident, IncidentSource
from app.models.job_orders import JobOrder
from app.models.payments import Payment
from app.models.portal import PortalUser
from app.models.service_records import ServiceRecord
from app.schemas.schemas import (
    MessageResponse,
    PortalChangePasswordRequest,
    PortalContractOut,
    PortalForgotPasswordRequest,
    PortalIncidentCreate,
    PortalIncidentOut,
    PortalInvoiceOut,
    PortalJobOrderDetailOut,
    PortalJobOrderOut,
    PortalLoginRequest,
    PortalLoginResult,
    PortalMeOut,
    PortalPaymentOut,
    PortalResetPasswordWithOtpRequest,
    PortalServiceRecordOut,
    PortalVerifyOtpRequest,
)
from app.services import audit, incidents as incident_svc, mailer
from app.services.auth import (
    create_purpose_token,
    decode_purpose_token,
    hash_password,
    validate_password_complexity,
    verify_password,
)

router = APIRouter(prefix="/api/portal", tags=["customer-portal"])

OTP_EXPIRE_MINUTES = 10
OTP_MAX_ATTEMPTS = 5
PORTAL_TOKEN_EXPIRE_MINUTES = 60 * 12  # 12 hours -- a customer, not a staff shift
LOGIN_LOCK_THRESHOLD = 5
LOGIN_LOCK_MINUTES = 15


def _hash_otp(code: str) -> str:
    return hashlib.sha256(code.encode("utf-8")).hexdigest()


def _issue_portal_token(portal_user: PortalUser) -> str:
    return create_purpose_token(portal_user.id, "portal", expire_minutes=PORTAL_TOKEN_EXPIRE_MINUTES)


def _issue_login_result(db: Session, portal_user: PortalUser) -> PortalLoginResult:
    """Mirrors app/routers/auth.py's _issue_login_result, but the portal
    always hands back the real token here (never a further gate) --
    must_change_password is reported alongside it for the frontend to
    act on, per this module's docstring."""
    if mailer.is_configured():
        code = f"{secrets.randbelow(1_000_000):06d}"
        otp = LoginOtp(
            portal_user_id=portal_user.id,
            code_hash=_hash_otp(code),
            purpose="login",
            expires_at=datetime.now(timezone.utc) + timedelta(minutes=OTP_EXPIRE_MINUTES),
        )
        db.add(otp)
        db.commit()
        try:
            mailer.send_email(
                to_email=portal_user.email,
                subject="Your Websoft Helpdesk Portal login code",
                body_text=(
                    f"Your one-time login code is {code}.\n\n"
                    f"It expires in {OTP_EXPIRE_MINUTES} minutes. If you didn't just try to "
                    "sign in, you can ignore this email."
                ),
            )
        except (mailer.MailerNotConfigured, mailer.MailerError):
            # Fail open, same reasoning as staff login -- never strand a
            # customer outside the portal because SMTP hiccupped.
            return PortalLoginResult(
                status="ok",
                portal_token=_issue_portal_token(portal_user),
                must_change_password=portal_user.must_change_password,
            )
        return PortalLoginResult(
            status="otp_required",
            otp_token=create_purpose_token(portal_user.id, "portal_otp", expire_minutes=OTP_EXPIRE_MINUTES),
        )
    return PortalLoginResult(
        status="ok",
        portal_token=_issue_portal_token(portal_user),
        must_change_password=portal_user.must_change_password,
    )


@router.post("/auth/login", response_model=PortalLoginResult)
def portal_login(payload: PortalLoginRequest, db: Session = Depends(get_db)):
    portal_user = db.query(PortalUser).filter(PortalUser.email == payload.email).first()
    generic_error = HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Incorrect email or password")
    if not portal_user or not portal_user.is_active:
        raise generic_error

    now = datetime.now(timezone.utc)
    if portal_user.locked_until and portal_user.locked_until > now:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail=(
                "Too many incorrect attempts. This login is locked until "
                f"{portal_user.locked_until.strftime('%H:%M')} (SGT server time)."
            ),
        )
    if portal_user.contact is None or portal_user.contact.customer is None or portal_user.contact.customer.is_archived:
        raise generic_error

    if not verify_password(payload.password, portal_user.hashed_password):
        portal_user.failed_attempts += 1
        if portal_user.failed_attempts >= LOGIN_LOCK_THRESHOLD:
            portal_user.locked_until = now + timedelta(minutes=LOGIN_LOCK_MINUTES)
            portal_user.failed_attempts = 0
        db.commit()
        raise generic_error

    portal_user.failed_attempts = 0
    portal_user.locked_until = None
    db.commit()
    return _issue_login_result(db, portal_user)


@router.post("/auth/verify-otp", response_model=PortalLoginResult)
def portal_verify_otp(payload: PortalVerifyOtpRequest, db: Session = Depends(get_db)):
    data = decode_purpose_token(payload.otp_token, "portal_otp")
    if not data:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="This code has expired -- please sign in again to get a new one.",
        )
    portal_user = db.get(PortalUser, uuid.UUID(data["sub"]))
    if not portal_user or not portal_user.is_active:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Account not found or inactive.")

    otp = (
        db.query(LoginOtp)
        .filter(LoginOtp.portal_user_id == portal_user.id, LoginOtp.purpose == "login", LoginOtp.consumed_at.is_(None))
        .order_by(LoginOtp.created_at.desc())
        .first()
    )
    now = datetime.now(timezone.utc)
    if not otp or otp.expires_at < now:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="This code has expired -- please sign in again to get a new one.",
        )
    if otp.attempts >= OTP_MAX_ATTEMPTS:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Too many incorrect attempts -- please sign in again to get a new code.",
        )
    if _hash_otp(payload.code) != otp.code_hash:
        otp.attempts += 1
        db.commit()
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Incorrect code.")

    otp.consumed_at = now
    portal_user.last_login_at = now
    db.commit()
    return PortalLoginResult(
        status="ok",
        portal_token=_issue_portal_token(portal_user),
        must_change_password=portal_user.must_change_password,
    )


@router.post("/auth/change-password", response_model=MessageResponse)
def portal_change_password(
    payload: PortalChangePasswordRequest,
    db: Session = Depends(get_db),
    portal_user: PortalUser = Depends(get_current_portal_user),
):
    try:
        validate_password_complexity(payload.new_password)
    except ValueError as e:
        raise HTTPException(status_code=422, detail=str(e)) from e

    portal_user.hashed_password = hash_password(payload.new_password)
    portal_user.must_change_password = False
    audit.record(
        db,
        entity_type="portal_user",
        entity_id=portal_user.id,
        action="password_changed_self",
        actor_user_id=None,
        actor_name=f"{portal_user.contact.name} (portal)",
        company_id=portal_user.company_id,
    )
    db.commit()
    return MessageResponse(message="Password updated.")


_FORGOT_PASSWORD_GENERIC_MESSAGE = (
    "If an account exists for that email, a one-time code has been sent to it."
)
_RESET_PASSWORD_GENERIC_ERROR = HTTPException(
    status_code=status.HTTP_401_UNAUTHORIZED, detail="Incorrect or expired code."
)


@router.post("/auth/forgot-password", response_model=MessageResponse)
def portal_forgot_password(payload: PortalForgotPasswordRequest, db: Session = Depends(get_db)):
    portal_user = db.query(PortalUser).filter(PortalUser.email == payload.email, PortalUser.is_active).first()
    if portal_user and mailer.is_configured():
        code = f"{secrets.randbelow(1_000_000):06d}"
        otp = LoginOtp(
            portal_user_id=portal_user.id,
            code_hash=_hash_otp(code),
            purpose="password_reset",
            expires_at=datetime.now(timezone.utc) + timedelta(minutes=OTP_EXPIRE_MINUTES),
        )
        db.add(otp)
        db.commit()
        try:
            mailer.send_email(
                to_email=portal_user.email,
                subject="Reset your Websoft Helpdesk Portal password",
                body_text=(
                    f"Your one-time password-reset code is {code}.\n\n"
                    f"It expires in {OTP_EXPIRE_MINUTES} minutes. If you didn't request a "
                    "password reset, you can ignore this email -- your password hasn't changed."
                ),
            )
        except (mailer.MailerNotConfigured, mailer.MailerError):
            pass  # still return the generic message below -- never reveal send failures
    return MessageResponse(message=_FORGOT_PASSWORD_GENERIC_MESSAGE)


@router.post("/auth/reset-password-otp", response_model=MessageResponse)
def portal_reset_password_with_otp(payload: PortalResetPasswordWithOtpRequest, db: Session = Depends(get_db)):
    portal_user = db.query(PortalUser).filter(PortalUser.email == payload.email).first()
    if not portal_user or not portal_user.is_active:
        raise _RESET_PASSWORD_GENERIC_ERROR

    otp = (
        db.query(LoginOtp)
        .filter(
            LoginOtp.portal_user_id == portal_user.id,
            LoginOtp.purpose == "password_reset",
            LoginOtp.consumed_at.is_(None),
        )
        .order_by(LoginOtp.created_at.desc())
        .first()
    )
    now = datetime.now(timezone.utc)
    if not otp or otp.expires_at < now or otp.attempts >= OTP_MAX_ATTEMPTS:
        raise _RESET_PASSWORD_GENERIC_ERROR
    if _hash_otp(payload.code) != otp.code_hash:
        otp.attempts += 1
        db.commit()
        raise _RESET_PASSWORD_GENERIC_ERROR

    try:
        validate_password_complexity(payload.new_password)
    except ValueError as e:
        raise HTTPException(status_code=422, detail=str(e)) from e

    otp.consumed_at = now
    portal_user.hashed_password = hash_password(payload.new_password)
    portal_user.must_change_password = False
    portal_user.failed_attempts = 0
    portal_user.locked_until = None
    audit.record(
        db,
        entity_type="portal_user",
        entity_id=portal_user.id,
        action="password_reset_via_forgot_password",
        actor_user_id=None,
        actor_name=f"{portal_user.contact.name} (portal)",
        company_id=portal_user.company_id,
    )
    db.commit()
    return MessageResponse(message="Password updated. You can now sign in with your new password.")


@router.get("/me", response_model=PortalMeOut)
def portal_me(portal_user: PortalUser = Depends(get_current_portal_user)):
    return PortalMeOut(
        contact_name=portal_user.contact.name,
        email=portal_user.email,
        customer_name=portal_user.contact.customer.name,
        must_change_password=portal_user.must_change_password,
    )


# ---- Data endpoints (design §6) ----------------------------------------
# Every query below filters on portal_user.contact.customer_id -- never
# on an id taken from the request -- so a portal user can only ever see
# their own customer's data. Where a path does carry an id (job order
# detail), a mismatch 404s rather than 403s: confirming that a document
# id exists for a *different* customer is exactly the information leak
# design §9.4 tests against.


@router.get("/contracts", response_model=list[PortalContractOut])
def portal_contracts(
    db: Session = Depends(get_db),
    portal_user: PortalUser = Depends(get_current_portal_user),
):
    customer_id = portal_user.contact.customer_id
    contracts = (
        db.query(Contract)
        .filter(Contract.customer_id == customer_id)
        .order_by(Contract.end_date.desc())
        .all()
    )
    return [PortalContractOut.from_model(c) for c in contracts]


def _engineer_name(db: Session, user_id: uuid.UUID | None) -> str | None:
    if user_id is None:
        return None
    user = db.get(User, user_id)
    return user.full_name if user else None


def _contract_number_map(db: Session, contract_ids: set[uuid.UUID]) -> dict[uuid.UUID, str]:
    """Batches the Contract lookup for a list of job orders/invoices in
    one query instead of one per row -- contract_ids may contain None,
    which the filter below simply never matches."""
    if not contract_ids:
        return {}
    rows = db.query(Contract.id, Contract.contract_number).filter(Contract.id.in_(contract_ids)).all()
    return {row[0]: row[1] for row in rows}


def _job_order_out(
    db: Session, job_order: JobOrder, contract_numbers: dict[uuid.UUID, str] | None = None
) -> PortalJobOrderOut:
    numbers = contract_numbers or {}
    return PortalJobOrderOut(
        id=job_order.id,
        job_order_number=job_order.job_order_number,
        subject=job_order.subject,
        job_order_type=job_order.job_order_type,
        status=job_order.status,
        assigned_engineer_name=_engineer_name(db, job_order.assigned_to_user_id),
        due_date=job_order.due_date,
        contract_id=job_order.contract_id,
        contract_number=numbers.get(job_order.contract_id) if job_order.contract_id else None,
    )


@router.get("/job-orders", response_model=list[PortalJobOrderOut])
def portal_job_orders(
    contract_id: uuid.UUID | None = None,
    db: Session = Depends(get_db),
    portal_user: PortalUser = Depends(get_current_portal_user),
):
    customer_id = portal_user.contact.customer_id
    query = db.query(JobOrder).filter(JobOrder.customer_id == customer_id)
    if contract_id is not None:
        # PORTAL-006: scope job orders (and via them, service records)
        # to one contract -- still customer-filtered above first, so a
        # contract_id belonging to another customer just returns empty,
        # not another customer's job orders.
        query = query.filter(JobOrder.contract_id == contract_id)
    job_orders = query.order_by(JobOrder.created_at.desc()).all()
    numbers = _contract_number_map(db, {jo.contract_id for jo in job_orders if jo.contract_id})
    return [_job_order_out(db, jo, numbers) for jo in job_orders]


@router.get("/job-orders/{job_order_id}", response_model=PortalJobOrderDetailOut)
def portal_job_order_detail(
    job_order_id: uuid.UUID,
    db: Session = Depends(get_db),
    portal_user: PortalUser = Depends(get_current_portal_user),
):
    customer_id = portal_user.contact.customer_id
    job_order = db.get(JobOrder, job_order_id)
    # A different customer's job order id is "not found", not "forbidden"
    # -- never confirm that the id exists at all (design §9.4).
    if not job_order or job_order.customer_id != customer_id:
        raise HTTPException(status_code=404, detail="Job order not found")

    records = (
        db.query(ServiceRecord)
        .filter(ServiceRecord.job_order_id == job_order.id)
        .order_by(ServiceRecord.work_date.desc())
        .all()
    )
    numbers = _contract_number_map(db, {job_order.contract_id} if job_order.contract_id else set())
    base = _job_order_out(db, job_order, numbers)
    return PortalJobOrderDetailOut(
        **base.model_dump(),
        service_records=[
            PortalServiceRecordOut(
                id=r.id,
                service_record_number=r.service_record_number,
                job_order_id=job_order.id,
                job_order_number=job_order.job_order_number,
                work_date=r.work_date,
                engineer_name=_engineer_name(db, r.employee_user_id) or "",
                minutes=r.rounded_minutes,
                completion_status=r.completion_status,
                status=r.status,
                contract_id=base.contract_id,
                contract_number=base.contract_number,
            )
            for r in records
        ],
    )


@router.get("/service-records", response_model=list[PortalServiceRecordOut])
def portal_service_records(
    contract_id: uuid.UUID | None = None,
    db: Session = Depends(get_db),
    portal_user: PortalUser = Depends(get_current_portal_user),
):
    customer_id = portal_user.contact.customer_id
    query = (
        db.query(ServiceRecord, JobOrder)
        .join(JobOrder, ServiceRecord.job_order_id == JobOrder.id)
        .filter(JobOrder.customer_id == customer_id)
    )
    if contract_id is not None:
        query = query.filter(JobOrder.contract_id == contract_id)  # PORTAL-006
    rows = query.order_by(ServiceRecord.work_date.desc()).all()
    numbers = _contract_number_map(db, {jo.contract_id for _, jo in rows if jo.contract_id})
    return [
        PortalServiceRecordOut(
            id=r.id,
            service_record_number=r.service_record_number,
            job_order_id=jo.id,
            job_order_number=jo.job_order_number,
            work_date=r.work_date,
            engineer_name=_engineer_name(db, r.employee_user_id) or "",
            minutes=r.rounded_minutes,
            completion_status=r.completion_status,
            status=r.status,
            contract_id=jo.contract_id,
            contract_number=numbers.get(jo.contract_id) if jo.contract_id else None,
        )
        for r, jo in rows
    ]


@router.get("/invoices", response_model=list[PortalInvoiceOut])
def portal_invoices(
    db: Session = Depends(get_db),
    portal_user: PortalUser = Depends(get_current_portal_user),
):
    """PORTAL-005: a customer's own Invoices, same figures as their PDF
    copy -- net, GST, total, amount paid, outstanding, status. Never the
    GP/cost fields on Invoice (those are staff-only)."""
    customer_id = portal_user.contact.customer_id
    invoices = (
        db.query(Invoice)
        .filter(Invoice.customer_id == customer_id)
        .order_by(Invoice.issued_at.desc())
        .all()
    )
    numbers = _contract_number_map(db, {inv.contract_id for inv in invoices if inv.contract_id})
    return [
        PortalInvoiceOut.from_model(inv, contract_number=numbers.get(inv.contract_id) if inv.contract_id else None)
        for inv in invoices
    ]


@router.get("/payments", response_model=list[PortalPaymentOut])
def portal_payments(
    db: Session = Depends(get_db),
    portal_user: PortalUser = Depends(get_current_portal_user),
):
    """PORTAL-005: a customer's own Payments (receipts) and which of
    their own invoices each one was allocated against."""
    customer_id = portal_user.contact.customer_id
    payments = (
        db.query(Payment)
        .filter(Payment.customer_id == customer_id)
        .order_by(Payment.payment_date.desc())
        .all()
    )
    invoice_ids = {a.invoice_id for p in payments for a in p.allocations}
    invoice_numbers = {}
    if invoice_ids:
        rows = db.query(Invoice.id, Invoice.invoice_number).filter(Invoice.id.in_(invoice_ids)).all()
        invoice_numbers = {row[0]: row[1] for row in rows}
    return [PortalPaymentOut.from_model(p, invoice_numbers=invoice_numbers) for p in payments]


@router.get("/incidents", response_model=list[PortalIncidentOut])
def portal_incidents(
    db: Session = Depends(get_db),
    portal_user: PortalUser = Depends(get_current_portal_user),
):
    customer_id = portal_user.contact.customer_id
    incidents = (
        db.query(Incident)
        .filter(Incident.customer_id == customer_id)
        .order_by(Incident.created_at.desc())
        .all()
    )
    job_order_ids = {i.converted_job_order_id for i in incidents if i.converted_job_order_id}
    job_order_numbers: dict[uuid.UUID, str] = {}
    if job_order_ids:
        rows = db.query(JobOrder.id, JobOrder.job_order_number).filter(JobOrder.id.in_(job_order_ids)).all()
        job_order_numbers = {row[0]: row[1] for row in rows}
    return [
        PortalIncidentOut(
            id=i.id,
            incident_number=i.incident_number,
            subject=i.subject,
            description=i.description,
            status=i.status,
            created_at=i.created_at,
            converted_job_order_number=(
                job_order_numbers.get(i.converted_job_order_id) if i.converted_job_order_id else None
            ),
        )
        for i in incidents
    ]


@router.post("/incidents", response_model=PortalIncidentOut)
def portal_create_incident(
    payload: PortalIncidentCreate,
    db: Session = Depends(get_db),
    portal_user: PortalUser = Depends(get_current_portal_user),
):
    contact = portal_user.contact
    incident = incident_svc.create_incident(
        db,
        company_id=portal_user.company_id,
        customer_id=contact.customer_id,
        source=IncidentSource.PORTAL,
        subject=payload.subject,
        description=payload.description,
        sender_name=contact.name,
        sender_email=portal_user.email,
        sender_phone=contact.phone,
        created_by_user_id=None,
        raised_by_portal_user_id=portal_user.id,
        portal_actor_name=f"{contact.name} (portal)",
    )
    db.commit()
    db.refresh(incident)
    return incident
