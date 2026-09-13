"""
eApproval Master service layer.

Provides the logic for:
- Looking up which approval rules apply to a document
- Creating approval requests
- Recording individual decisions (approve/reject)
- Resolving the overall request status based on the authority's mode
"""
import uuid
from datetime import datetime, timezone
from decimal import Decimal

from sqlalchemy.orm import Session, selectinload

from app.models.approvals import (
    ApprovalAuthority,
    ApprovalAuthorityMember,
    ApprovalDecision,
    ApprovalDecisionValue,
    ApprovalMode,
    ApprovalRequest,
    ApprovalRule,
    ApprovalStatus,
)
from app.models.documents import DocumentEntityType


class ApprovalError(Exception):
    """An approval rule was broken."""


def find_applicable_rules(
    db: Session,
    company_id: uuid.UUID,
    entity_type: DocumentEntityType,
    amount: Decimal | None = None,
) -> list[ApprovalRule]:
    """Find all active approval rules that match the given document type
    and (optionally) value threshold."""
    query = (
        db.query(ApprovalRule)
        .join(ApprovalAuthority)
        .filter(
            ApprovalAuthority.company_id == company_id,
            ApprovalAuthority.is_active.is_(True),
            ApprovalRule.is_active.is_(True),
            ApprovalRule.entity_type == entity_type,
        )
    )
    rules = query.order_by(ApprovalRule.priority).all()

    if amount is not None:
        return [r for r in rules if r.threshold_amount is None or amount >= r.threshold_amount]
    return [r for r in rules if r.threshold_amount is None]


def submit_for_approval(
    db: Session,
    *,
    company_id: uuid.UUID,
    entity_type: DocumentEntityType,
    entity_id: uuid.UUID,
    requested_by_user_id: uuid.UUID,
    amount: Decimal | None = None,
) -> list[ApprovalRequest]:
    """Submit a document for approval under all applicable rules.

    Returns one ApprovalRequest per matching rule. If no rules match,
    returns an empty list (the document needs no approval).
    """
    rules = find_applicable_rules(db, company_id, entity_type, amount)
    requests = []
    for rule in rules:
        # Don't create duplicate requests for the same entity + rule
        existing = (
            db.query(ApprovalRequest)
            .filter(
                ApprovalRequest.entity_type == entity_type,
                ApprovalRequest.entity_id == entity_id,
                ApprovalRequest.rule_id == rule.id,
                ApprovalRequest.status == ApprovalStatus.PENDING,
            )
            .first()
        )
        if existing:
            requests.append(existing)
            continue

        req = ApprovalRequest(
            company_id=company_id,
            entity_type=entity_type,
            entity_id=entity_id,
            rule_id=rule.id,
            authority_id=rule.authority_id,
            requested_by_user_id=requested_by_user_id,
        )
        db.add(req)
        db.flush()
        requests.append(req)
    return requests


def record_decision(
    db: Session,
    *,
    request_id: uuid.UUID,
    user_id: uuid.UUID,
    decision: ApprovalDecisionValue,
    comment: str | None = None,
) -> ApprovalRequest:
    """Record one approver's decision and resolve the request if
    the authority's mode is satisfied."""
    req = (
        db.query(ApprovalRequest)
        .options(selectinload(ApprovalRequest.decisions))
        .get(request_id)
    )
    if req is None:
        raise ApprovalError("Approval request not found.")
    if req.status != ApprovalStatus.PENDING:
        raise ApprovalError(f"This request has already been {req.status.value}.")

    # Verify the user is an authority member
    is_member = (
        db.query(ApprovalAuthorityMember)
        .filter(
            ApprovalAuthorityMember.authority_id == req.authority_id,
            ApprovalAuthorityMember.user_id == user_id,
        )
        .first()
    )
    if not is_member:
        raise ApprovalError("You are not assigned to this approval authority.")

    # Check for duplicate decision
    existing = next((d for d in req.decisions if d.user_id == user_id), None)
    if existing:
        raise ApprovalError("You have already recorded a decision on this request.")

    dec = ApprovalDecision(
        request_id=request_id,
        user_id=user_id,
        decision=decision,
        comment=comment,
    )
    db.add(dec)
    db.flush()

    # Resolve the request
    _resolve_request(db, req)
    return req


def _resolve_request(db: Session, req: ApprovalRequest) -> None:
    """Check if the request can be resolved based on the authority's mode."""
    authority = db.get(ApprovalAuthority, req.authority_id)
    if authority is None:
        return

    decisions = req.decisions
    members = (
        db.query(ApprovalAuthorityMember)
        .filter(ApprovalAuthorityMember.authority_id == authority.id)
        .all()
    )
    member_count = len(members)

    # Any rejection immediately rejects the whole request
    if any(d.decision == ApprovalDecisionValue.REJECTED for d in decisions):
        req.status = ApprovalStatus.REJECTED
        req.resolved_at = datetime.now(timezone.utc)
        return

    approved_count = sum(1 for d in decisions if d.decision == ApprovalDecisionValue.APPROVED)

    if authority.mode == ApprovalMode.ANY_ONE:
        if approved_count >= 1:
            req.status = ApprovalStatus.APPROVED
            req.resolved_at = datetime.now(timezone.utc)
    elif authority.mode == ApprovalMode.ALL_MUST:
        if approved_count >= member_count:
            req.status = ApprovalStatus.APPROVED
            req.resolved_at = datetime.now(timezone.utc)


def list_pending_for_user(
    db: Session,
    company_id: uuid.UUID,
    user_id: uuid.UUID,
) -> list[ApprovalRequest]:
    """List all pending approval requests where the user is an
    authority member and hasn't decided yet."""
    # Get all authorities this user is a member of
    user_authority_ids = [
        m.authority_id
        for m in db.query(ApprovalAuthorityMember)
        .filter(ApprovalAuthorityMember.user_id == user_id)
        .all()
    ]
    if not user_authority_ids:
        return []

    # Get pending requests for those authorities
    pending = (
        db.query(ApprovalRequest)
        .options(selectinload(ApprovalRequest.decisions))
        .filter(
            ApprovalRequest.company_id == company_id,
            ApprovalRequest.authority_id.in_(user_authority_ids),
            ApprovalRequest.status == ApprovalStatus.PENDING,
        )
        .order_by(ApprovalRequest.requested_at)
        .all()
    )

    # Exclude requests where this user already decided
    return [
        r for r in pending
        if not any(d.user_id == user_id for d in r.decisions)
    ]


def list_requests_for_entity(
    db: Session,
    company_id: uuid.UUID,
    entity_type: DocumentEntityType,
    entity_id: uuid.UUID,
) -> list[ApprovalRequest]:
    """All approval requests (any status) for a given document."""
    return (
        db.query(ApprovalRequest)
        .options(selectinload(ApprovalRequest.decisions))
        .filter(
            ApprovalRequest.company_id == company_id,
            ApprovalRequest.entity_type == entity_type,
            ApprovalRequest.entity_id == entity_id,
        )
        .order_by(ApprovalRequest.requested_at)
        .all()
    )
