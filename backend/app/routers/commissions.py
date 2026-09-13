"""
Commission Management -- payout generation, approval, clawback, payment.

Resolves open items 6.3 (approval), 6.4 (clawback), 6.5 (payout) in
docs/open-business-decisions.md.
"""
import uuid
from datetime import date

from fastapi import APIRouter, Depends, HTTPException, Query
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.models.commissions import CommissionPayout, CommissionPayoutStatus
from app.models.core import User
from app.models.groups import AccessLevel
from app.schemas.schemas import (
    CommissionPayoutCreate,
    CommissionPayoutMarkPaid,
    CommissionPayoutOut,
    CommissionPayoutReject,
)
from app.services import audit
from app.services import commissions as comm_svc
from app.services.authority import require_module_access

router = APIRouter(prefix="/api/commissions", tags=["commissions"])
MODULE = "accounting_reports"


@router.post("/payouts/generate", response_model=list[CommissionPayoutOut])
def generate_payouts(
    body: CommissionPayoutCreate,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.FULL)),
):
    """Generate DRAFT commission payouts for a given month."""
    try:
        payouts = comm_svc.generate_payouts(
            db,
            company_id=current_user.company_id,
            period_month=body.period_month,
            created_by_user_id=current_user.id,
        )
    except comm_svc.CommissionError as exc:
        raise HTTPException(status_code=422, detail=str(exc))

    for p in payouts:
        audit.record(
            db,
            entity_type="commission_payout",
            entity_id=p.id,
            action="generated",
            actor_user_id=current_user.id,
            new_value={
                "payout_number": p.payout_number,
                "period_month": p.period_month,
                "amount_sgd": float(p.amount_sgd),
                "sales_staff_id": str(p.sales_staff_id),
            },
        )
    db.commit()
    return _list_payouts_for_month(db, current_user.company_id, body.period_month)


@router.get("/payouts", response_model=list[CommissionPayoutOut])
def list_payouts(
    period_month: str | None = Query(None),
    status: str | None = Query(None),
    sales_staff_id: uuid.UUID | None = Query(None),
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    """List commission payouts with optional filters."""
    q = db.query(CommissionPayout).filter(
        CommissionPayout.company_id == current_user.company_id
    )
    if period_month:
        q = q.filter(CommissionPayout.period_month == period_month)
    if status:
        try:
            q = q.filter(CommissionPayout.status == CommissionPayoutStatus(status))
        except ValueError:
            raise HTTPException(status_code=422, detail=f"Invalid status: {status}")
    if sales_staff_id:
        q = q.filter(CommissionPayout.sales_staff_id == sales_staff_id)
    return q.order_by(CommissionPayout.period_month.desc(), CommissionPayout.created_at.desc()).all()


@router.get("/payouts/{payout_id}", response_model=CommissionPayoutOut)
def get_payout(
    payout_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    """Get a single commission payout by ID."""
    payout = db.get(CommissionPayout, payout_id)
    if not payout or payout.company_id != current_user.company_id:
        raise HTTPException(status_code=404, detail="Payout not found")
    return payout


@router.post("/payouts/{payout_id}/submit", response_model=CommissionPayoutOut)
def submit_payout(
    payout_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    """Submit a DRAFT payout for approval (6.3)."""
    try:
        payout = comm_svc.submit_payout(
            db, payout_id, current_user.company_id, current_user.id
        )
    except comm_svc.CommissionError as exc:
        raise HTTPException(status_code=422, detail=str(exc))

    audit.record(
        db,
        entity_type="commission_payout",
        entity_id=payout.id,
        action="submitted",
        actor_user_id=current_user.id,
        old_value={"status": "draft"},
        new_value={"status": "pending_approval"},
    )
    db.commit()
    db.refresh(payout)
    return payout


@router.post("/payouts/{payout_id}/approve", response_model=CommissionPayoutOut)
def approve_payout(
    payout_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.FULL)),
):
    """Approve a pending payout (6.3)."""
    try:
        payout = comm_svc.approve_payout(
            db, payout_id, current_user.company_id, current_user.id
        )
    except comm_svc.CommissionError as exc:
        raise HTTPException(status_code=422, detail=str(exc))

    audit.record(
        db,
        entity_type="commission_payout",
        entity_id=payout.id,
        action="approved",
        actor_user_id=current_user.id,
        old_value={"status": "pending_approval"},
        new_value={"status": "approved"},
    )
    db.commit()
    db.refresh(payout)
    return payout


@router.post("/payouts/{payout_id}/reject", response_model=CommissionPayoutOut)
def reject_payout(
    payout_id: uuid.UUID,
    body: CommissionPayoutReject,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.FULL)),
):
    """Reject a pending payout back to DRAFT (6.3)."""
    try:
        payout = comm_svc.reject_payout(
            db, payout_id, current_user.company_id, current_user.id, body.reason
        )
    except comm_svc.CommissionError as exc:
        raise HTTPException(status_code=422, detail=str(exc))

    audit.record(
        db,
        entity_type="commission_payout",
        entity_id=payout.id,
        action="rejected",
        actor_user_id=current_user.id,
        old_value={"status": "pending_approval"},
        new_value={"status": "draft"},
    )
    db.commit()
    db.refresh(payout)
    return payout


@router.post("/payouts/{payout_id}/pay", response_model=CommissionPayoutOut)
def pay_payout(
    payout_id: uuid.UUID,
    body: CommissionPayoutMarkPaid,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.FULL)),
):
    """Mark an approved payout as paid (6.5). Finance-administered."""
    try:
        payout = comm_svc.mark_paid(
            db,
            payout_id,
            current_user.company_id,
            current_user.id,
            body.paid_date,
            body.paid_reference,
        )
    except comm_svc.CommissionError as exc:
        raise HTTPException(status_code=422, detail=str(exc))

    audit.record(
        db,
        entity_type="commission_payout",
        entity_id=payout.id,
        action="paid",
        actor_user_id=current_user.id,
        old_value={"status": "approved"},
        new_value={
            "status": "paid",
            "paid_date": str(body.paid_date),
            "paid_reference": body.paid_reference,
        },
    )
    db.commit()
    db.refresh(payout)
    return payout


@router.post("/payouts/{payout_id}/cancel", response_model=CommissionPayoutOut)
def cancel_payout(
    payout_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.FULL)),
):
    """Cancel a payout (before it's paid)."""
    try:
        payout = comm_svc.cancel_payout(
            db, payout_id, current_user.company_id
        )
    except comm_svc.CommissionError as exc:
        raise HTTPException(status_code=422, detail=str(exc))

    audit.record(
        db,
        entity_type="commission_payout",
        entity_id=payout.id,
        action="cancelled",
        actor_user_id=current_user.id,
        new_value={"status": "cancelled"},
    )
    db.commit()
    db.refresh(payout)
    return payout


@router.post("/payouts/submit-all", response_model=list[CommissionPayoutOut])
def submit_all_for_month(
    body: CommissionPayoutCreate,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    """Submit all DRAFT payouts for a month at once."""
    payouts = (
        db.query(CommissionPayout)
        .filter(
            CommissionPayout.company_id == current_user.company_id,
            CommissionPayout.period_month == body.period_month,
            CommissionPayout.status == CommissionPayoutStatus.DRAFT,
        )
        .all()
    )
    for p in payouts:
        comm_svc.submit_payout(db, p.id, current_user.company_id, current_user.id)
        audit.record(
            db,
            entity_type="commission_payout",
            entity_id=p.id,
            action="submitted",
            actor_user_id=current_user.id,
            old_value={"status": "draft"},
            new_value={"status": "pending_approval"},
        )
    db.commit()
    return _list_payouts_for_month(db, current_user.company_id, body.period_month)


@router.post("/payouts/approve-all", response_model=list[CommissionPayoutOut])
def approve_all_for_month(
    body: CommissionPayoutCreate,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.FULL)),
):
    """Approve all PENDING_APPROVAL payouts for a month at once."""
    payouts = (
        db.query(CommissionPayout)
        .filter(
            CommissionPayout.company_id == current_user.company_id,
            CommissionPayout.period_month == body.period_month,
            CommissionPayout.status == CommissionPayoutStatus.PENDING_APPROVAL,
        )
        .all()
    )
    for p in payouts:
        comm_svc.approve_payout(db, p.id, current_user.company_id, current_user.id)
        audit.record(
            db,
            entity_type="commission_payout",
            entity_id=p.id,
            action="approved",
            actor_user_id=current_user.id,
            old_value={"status": "pending_approval"},
            new_value={"status": "approved"},
        )
    db.commit()
    return _list_payouts_for_month(db, current_user.company_id, body.period_month)


def _list_payouts_for_month(
    db: Session, company_id: uuid.UUID, period_month: str
) -> list[CommissionPayout]:
    return (
        db.query(CommissionPayout)
        .filter(
            CommissionPayout.company_id == company_id,
            CommissionPayout.period_month == period_month,
        )
        .order_by(CommissionPayout.created_at.desc())
        .all()
    )
