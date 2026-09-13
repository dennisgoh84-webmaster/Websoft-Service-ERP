"""
eApproval Master — authority CRUD, member management, rule CRUD,
submit for approval, record decisions, list pending.

Admin endpoints (authority/rule CRUD, member management) require
core_administration FULL access. Approval actions (submit, decide,
list pending) require EDIT access.
"""
import uuid
from decimal import Decimal

from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.orm import Session, selectinload

from app.core.database import get_db
from app.models.approvals import (
    ApprovalAuthority,
    ApprovalAuthorityMember,
    ApprovalRule,
)
from app.models.core import User
from app.models.groups import AccessLevel
from app.schemas.schemas import (
    ApprovalAuthorityCreate,
    ApprovalAuthorityMemberAdd,
    ApprovalAuthorityMemberOut,
    ApprovalAuthorityOut,
    ApprovalAuthorityUpdate,
    ApprovalDecisionRequest,
    ApprovalRequestOut,
    ApprovalRuleCreate,
    ApprovalRuleOut,
    ApprovalRuleUpdate,
    ApprovalSubmitRequest,
)
from app.services import audit
from app.services import approvals as approval_svc
from app.services.authority import require_module_access

router = APIRouter(prefix="/api/approvals", tags=["approvals"])
MODULE = "core_administration"


# ── Authority CRUD ─────────────────────────────────────────────────


def _authority_or_404(db: Session, authority_id: uuid.UUID, company_id: uuid.UUID) -> ApprovalAuthority:
    authority = (
        db.query(ApprovalAuthority)
        .options(
            selectinload(ApprovalAuthority.members),
            selectinload(ApprovalAuthority.rules),
        )
        .filter(
            ApprovalAuthority.id == authority_id,
            ApprovalAuthority.company_id == company_id,
        )
        .first()
    )
    if not authority:
        raise HTTPException(status_code=404, detail="Approval authority not found")
    return authority


@router.get("/authorities", response_model=list[ApprovalAuthorityOut])
def list_authorities(
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    """List all approval authorities for the current company."""
    return (
        db.query(ApprovalAuthority)
        .options(
            selectinload(ApprovalAuthority.members),
            selectinload(ApprovalAuthority.rules),
        )
        .filter(ApprovalAuthority.company_id == current_user.company_id)
        .order_by(ApprovalAuthority.name)
        .all()
    )


@router.get("/authorities/{authority_id}", response_model=ApprovalAuthorityOut)
def get_authority(
    authority_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    return _authority_or_404(db, authority_id, current_user.company_id)


@router.post("/authorities", response_model=ApprovalAuthorityOut, status_code=201)
def create_authority(
    body: ApprovalAuthorityCreate,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.FULL)),
):
    authority = ApprovalAuthority(
        company_id=current_user.company_id,
        name=body.name,
        description=body.description,
        mode=body.mode,
        bank_account_id=body.bank_account_id,
    )
    db.add(authority)
    db.flush()
    audit.record(
        db,
        user_id=current_user.id,
        company_id=current_user.company_id,
        action="approval_authority_create",
        entity_type="approval_authority",
        entity_id=authority.id,
        details={"name": authority.name, "mode": authority.mode.value},
    )
    db.commit()
    return _authority_or_404(db, authority.id, current_user.company_id)


@router.patch("/authorities/{authority_id}", response_model=ApprovalAuthorityOut)
def update_authority(
    authority_id: uuid.UUID,
    body: ApprovalAuthorityUpdate,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.FULL)),
):
    authority = _authority_or_404(db, authority_id, current_user.company_id)
    changes = body.model_dump(exclude_unset=True)
    for key, value in changes.items():
        setattr(authority, key, value)
    audit.record(
        db,
        user_id=current_user.id,
        company_id=current_user.company_id,
        action="approval_authority_update",
        entity_type="approval_authority",
        entity_id=authority.id,
        details=changes,
    )
    db.commit()
    return _authority_or_404(db, authority_id, current_user.company_id)


# ── Authority members ──────────────────────────────────────────────


@router.post(
    "/authorities/{authority_id}/members",
    response_model=ApprovalAuthorityMemberOut,
    status_code=201,
)
def add_member(
    authority_id: uuid.UUID,
    body: ApprovalAuthorityMemberAdd,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.FULL)),
):
    """Add a user to an approval authority."""
    authority = _authority_or_404(db, authority_id, current_user.company_id)

    # Check for duplicate
    existing = (
        db.query(ApprovalAuthorityMember)
        .filter(
            ApprovalAuthorityMember.authority_id == authority.id,
            ApprovalAuthorityMember.user_id == body.user_id,
        )
        .first()
    )
    if existing:
        raise HTTPException(status_code=409, detail="User is already a member of this authority")

    member = ApprovalAuthorityMember(
        authority_id=authority.id,
        user_id=body.user_id,
    )
    db.add(member)
    db.flush()
    audit.record(
        db,
        user_id=current_user.id,
        company_id=current_user.company_id,
        action="approval_member_add",
        entity_type="approval_authority",
        entity_id=authority.id,
        details={"member_user_id": str(body.user_id)},
    )
    db.commit()
    return member


@router.delete("/authorities/{authority_id}/members/{member_id}", status_code=204)
def remove_member(
    authority_id: uuid.UUID,
    member_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.FULL)),
):
    """Remove a user from an approval authority."""
    authority = _authority_or_404(db, authority_id, current_user.company_id)
    member = db.get(ApprovalAuthorityMember, member_id)
    if not member or member.authority_id != authority.id:
        raise HTTPException(status_code=404, detail="Member not found")

    audit.record(
        db,
        user_id=current_user.id,
        company_id=current_user.company_id,
        action="approval_member_remove",
        entity_type="approval_authority",
        entity_id=authority.id,
        details={"member_user_id": str(member.user_id)},
    )
    db.delete(member)
    db.commit()


# ── Approval rules ─────────────────────────────────────────────────


@router.post("/rules", response_model=ApprovalRuleOut, status_code=201)
def create_rule(
    body: ApprovalRuleCreate,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.FULL)),
):
    """Create an approval rule binding an authority to a document type."""
    # Verify authority belongs to company
    _authority_or_404(db, body.authority_id, current_user.company_id)

    rule = ApprovalRule(
        authority_id=body.authority_id,
        entity_type=body.entity_type,
        threshold_amount=Decimal(str(body.threshold_amount)) if body.threshold_amount is not None else None,
        priority=body.priority,
    )
    db.add(rule)
    db.flush()
    audit.record(
        db,
        user_id=current_user.id,
        company_id=current_user.company_id,
        action="approval_rule_create",
        entity_type="approval_rule",
        entity_id=rule.id,
        details={
            "authority_id": str(body.authority_id),
            "entity_type": body.entity_type.value,
            "threshold_amount": body.threshold_amount,
        },
    )
    db.commit()
    db.refresh(rule)
    return rule


@router.patch("/rules/{rule_id}", response_model=ApprovalRuleOut)
def update_rule(
    rule_id: uuid.UUID,
    body: ApprovalRuleUpdate,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.FULL)),
):
    """Update an approval rule."""
    rule = db.get(ApprovalRule, rule_id)
    if not rule:
        raise HTTPException(status_code=404, detail="Rule not found")
    # Verify authority ownership
    _authority_or_404(db, rule.authority_id, current_user.company_id)

    changes = body.model_dump(exclude_unset=True)
    if "threshold_amount" in changes and changes["threshold_amount"] is not None:
        changes["threshold_amount"] = Decimal(str(changes["threshold_amount"]))
    for key, value in changes.items():
        setattr(rule, key, value)

    audit.record(
        db,
        user_id=current_user.id,
        company_id=current_user.company_id,
        action="approval_rule_update",
        entity_type="approval_rule",
        entity_id=rule.id,
        details=changes,
    )
    db.commit()
    db.refresh(rule)
    return rule


@router.delete("/rules/{rule_id}", status_code=204)
def delete_rule(
    rule_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.FULL)),
):
    """Deactivate an approval rule (soft-delete)."""
    rule = db.get(ApprovalRule, rule_id)
    if not rule:
        raise HTTPException(status_code=404, detail="Rule not found")
    _authority_or_404(db, rule.authority_id, current_user.company_id)

    rule.is_active = False
    audit.record(
        db,
        user_id=current_user.id,
        company_id=current_user.company_id,
        action="approval_rule_deactivate",
        entity_type="approval_rule",
        entity_id=rule.id,
    )
    db.commit()


# ── Submit / Decide / List ─────────────────────────────────────────


@router.post("/submit", response_model=list[ApprovalRequestOut])
def submit_for_approval(
    body: ApprovalSubmitRequest,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    """Submit a document for approval under all applicable rules."""
    amount = Decimal(str(body.amount)) if body.amount is not None else None
    requests = approval_svc.submit_for_approval(
        db,
        company_id=current_user.company_id,
        entity_type=body.entity_type,
        entity_id=body.entity_id,
        requested_by_user_id=current_user.id,
        amount=amount,
    )
    audit.record(
        db,
        user_id=current_user.id,
        company_id=current_user.company_id,
        action="approval_submit",
        entity_type=body.entity_type.value,
        entity_id=body.entity_id,
        details={"amount": body.amount, "rules_matched": len(requests)},
    )
    db.commit()
    return requests


@router.post("/requests/{request_id}/decide", response_model=ApprovalRequestOut)
def decide(
    request_id: uuid.UUID,
    body: ApprovalDecisionRequest,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    """Record the current user's approval/rejection decision."""
    try:
        req = approval_svc.record_decision(
            db,
            request_id=request_id,
            user_id=current_user.id,
            decision=body.decision,
            comment=body.comment,
        )
    except approval_svc.ApprovalError as exc:
        raise HTTPException(status_code=422, detail=str(exc))

    audit.record(
        db,
        user_id=current_user.id,
        company_id=current_user.company_id,
        action="approval_decision",
        entity_type=req.entity_type.value,
        entity_id=req.entity_id,
        details={
            "request_id": str(request_id),
            "decision": body.decision.value,
            "new_status": req.status.value,
        },
    )
    db.commit()
    return req


@router.get("/pending", response_model=list[ApprovalRequestOut])
def list_pending(
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    """List all pending approval requests assigned to the current user."""
    return approval_svc.list_pending_for_user(
        db, current_user.company_id, current_user.id
    )


@router.get("/entity/{entity_type}/{entity_id}", response_model=list[ApprovalRequestOut])
def list_for_entity(
    entity_type: str,
    entity_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    """List all approval requests (any status) for a specific document."""
    from app.models.documents import DocumentEntityType

    try:
        doc_type = DocumentEntityType(entity_type)
    except ValueError:
        raise HTTPException(status_code=422, detail=f"Invalid entity type: {entity_type}")

    return approval_svc.list_requests_for_entity(
        db, current_user.company_id, doc_type, entity_id
    )
