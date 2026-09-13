"""Staff Management — CC staff admin + push support logins to client DBs."""
from datetime import datetime, timezone

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy import create_engine, desc, text
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import get_current_admin
from app.models.admin import AdminUser
from app.models.clients import Client
from app.models.push_logs import PushLog, PushType
from app.models.staff import SupportLogin, SupportLoginStatus
from app.schemas import (
    AdminUserOut, StaffCreate, StaffUpdate,
    SupportLoginPush, SupportLoginOut,
)
from app.services.auth import hash_password
from app.services.client_db import _build_dsn, _check_alembic_version

router = APIRouter(prefix="/api/staff", tags=["staff"])


# ── Staff CRUD ───────────────────────────────────────────────────────

@router.get("/", response_model=list[AdminUserOut])
def list_staff(
    db: Session = Depends(get_db),
    admin: AdminUser = Depends(get_current_admin),
):
    return db.query(AdminUser).order_by(AdminUser.created_at).all()


@router.post("/", response_model=AdminUserOut, status_code=status.HTTP_201_CREATED)
def create_staff(
    body: StaffCreate,
    db: Session = Depends(get_db),
    admin: AdminUser = Depends(get_current_admin),
):
    # Only super_admin can create staff
    if admin.role not in ("super_admin",):
        raise HTTPException(403, "Only super admins can create staff")

    existing = db.query(AdminUser).filter(AdminUser.username == body.username).first()
    if existing:
        raise HTTPException(400, f"Username '{body.username}' already exists")

    valid_roles = ("super_admin", "admin", "support_engineer", "viewer")
    if body.role not in valid_roles:
        raise HTTPException(400, f"Invalid role. Must be one of: {', '.join(valid_roles)}")

    user = AdminUser(
        username=body.username,
        full_name=body.full_name,
        email=body.email,
        hashed_password=hash_password(body.password),
        role=body.role,
    )
    db.add(user)
    db.commit()
    db.refresh(user)
    return user


@router.patch("/{user_id}", response_model=AdminUserOut)
def update_staff(
    user_id: str,
    body: StaffUpdate,
    db: Session = Depends(get_db),
    admin: AdminUser = Depends(get_current_admin),
):
    if admin.role not in ("super_admin",):
        raise HTTPException(403, "Only super admins can update staff")

    user = db.get(AdminUser, user_id)
    if not user:
        raise HTTPException(404, "Staff not found")

    if body.full_name is not None:
        user.full_name = body.full_name
    if body.email is not None:
        user.email = body.email
    if body.role is not None:
        user.role = body.role
    if body.is_active is not None:
        user.is_active = body.is_active
    if body.password is not None:
        user.hashed_password = hash_password(body.password)

    db.commit()
    db.refresh(user)
    return user


@router.delete("/{user_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_staff(
    user_id: str,
    db: Session = Depends(get_db),
    admin: AdminUser = Depends(get_current_admin),
):
    if admin.role not in ("super_admin",):
        raise HTTPException(403, "Only super admins can delete staff")

    user = db.get(AdminUser, user_id)
    if not user:
        raise HTTPException(404, "Staff not found")
    if str(user.id) == str(admin.id):
        raise HTTPException(400, "Cannot delete yourself")

    db.delete(user)
    db.commit()


# ── Support Login Push ───────────────────────────────────────────────

@router.get("/support-logins", response_model=list[SupportLoginOut])
def list_support_logins(
    client_id: str | None = None,
    db: Session = Depends(get_db),
    admin: AdminUser = Depends(get_current_admin),
):
    q = db.query(SupportLogin).order_by(desc(SupportLogin.pushed_at))
    if client_id:
        q = q.filter(SupportLogin.client_id == client_id)
    return q.limit(100).all()


@router.post("/support-logins/push")
def push_support_login(
    body: SupportLoginPush,
    db: Session = Depends(get_db),
    admin: AdminUser = Depends(get_current_admin),
):
    """Push a support staff login to a client's ERP database.

    Creates a user row in the client's `users` table with the
    SUPPORT_ENGINEER role, linked to the first company in that DB.
    Records the push in Central Command's support_logins table.
    """
    client = db.get(Client, str(body.client_id))
    if not client:
        raise HTTPException(404, "Client not found")

    target_admin = db.get(AdminUser, str(body.admin_user_id))
    if not target_admin:
        raise HTTPException(404, "Staff member not found")

    try:
        engine = create_engine(_build_dsn(client), pool_pre_ping=True)
        _check_alembic_version(client, engine)

        with engine.begin() as conn:
            # Get the first company in the client DB
            result = conn.execute(text(
                "SELECT id FROM companies WHERE is_active = true LIMIT 1"
            ))
            company_row = result.fetchone()
            if not company_row:
                raise Exception("No active company found in client DB")

            company_id = str(company_row[0])

            # Check if email already exists in client DB
            result = conn.execute(text(
                "SELECT id FROM users WHERE email = :email"
            ), {"email": body.login_email})
            existing = result.fetchone()

            if existing:
                # Re-enable the existing user
                client_user_id = str(existing[0])
                conn.execute(text("""
                    UPDATE users SET is_active = true,
                        hashed_password = :pwd,
                        must_change_password = true
                    WHERE id = :uid
                """), {
                    "uid": client_user_id,
                    "pwd": hash_password(body.login_password),
                })
            else:
                # Create new user with support_engineer role
                result = conn.execute(text("""
                    INSERT INTO users (id, company_id, email, hashed_password,
                        full_name, role, must_change_password, is_active)
                    VALUES (gen_random_uuid(), :company_id::uuid, :email,
                        :pwd, :full_name, 'SUPPORT_ENGINEER', true, true)
                    RETURNING id
                """), {
                    "company_id": company_id,
                    "email": body.login_email,
                    "pwd": hash_password(body.login_password),
                    "full_name": f"[Support] {target_admin.full_name}",
                })
                client_user_id = str(result.fetchone()[0])

                # Grant access to the company
                conn.execute(text("""
                    INSERT INTO user_company_access (id, user_id, company_id)
                    VALUES (gen_random_uuid(), :user_id::uuid, :company_id::uuid)
                    ON CONFLICT DO NOTHING
                """), {
                    "user_id": client_user_id,
                    "company_id": company_id,
                })

        engine.dispose()

        # Record in CC's support_logins
        support_login = SupportLogin(
            admin_user_id=body.admin_user_id,
            client_id=client.id,
            login_email=body.login_email,
            client_user_id=client_user_id,
            reason=body.reason,
            pushed_by=admin.id,
        )
        db.add(support_login)

        # Push log
        db.add(PushLog(
            client_id=client.id,
            push_type=PushType.SUPPORT_LOGIN,
            detail=f"Pushed support login '{body.login_email}' for {target_admin.full_name}",
            success=True,
            pushed_by=admin.id,
        ))

        client.last_connected_at = datetime.now(timezone.utc)
        db.commit()

        return {
            "success": True,
            "client": client.code,
            "login_email": body.login_email,
            "client_user_id": client_user_id,
        }

    except Exception as e:
        db.add(PushLog(
            client_id=client.id,
            push_type=PushType.SUPPORT_LOGIN,
            detail=f"Support login push failed for '{body.login_email}'",
            success=False,
            error_message=str(e),
            pushed_by=admin.id,
        ))
        db.commit()
        raise HTTPException(500, f"Failed to push support login: {e}")


@router.post("/support-logins/{login_id}/revoke")
def revoke_support_login(
    login_id: str,
    db: Session = Depends(get_db),
    admin: AdminUser = Depends(get_current_admin),
):
    """Revoke (disable) a support login on a client's ERP database."""
    support_login = db.get(SupportLogin, login_id)
    if not support_login:
        raise HTTPException(404, "Support login not found")

    if support_login.status == SupportLoginStatus.REVOKED:
        raise HTTPException(400, "Already revoked")

    client = db.get(Client, str(support_login.client_id))
    if not client:
        raise HTTPException(404, "Client not found")

    try:
        engine = create_engine(_build_dsn(client), pool_pre_ping=True)
        with engine.begin() as conn:
            conn.execute(text(
                "UPDATE users SET is_active = false WHERE id = :uid"
            ), {"uid": support_login.client_user_id})
        engine.dispose()

        support_login.status = SupportLoginStatus.REVOKED
        support_login.revoked_at = datetime.now(timezone.utc)

        db.add(PushLog(
            client_id=client.id,
            push_type=PushType.SUPPORT_LOGIN,
            detail=f"Revoked support login '{support_login.login_email}'",
            success=True,
            pushed_by=admin.id,
        ))

        client.last_connected_at = datetime.now(timezone.utc)
        db.commit()

        return {"success": True, "client": client.code, "login_email": support_login.login_email}

    except Exception as e:
        raise HTTPException(500, f"Failed to revoke: {e}")
