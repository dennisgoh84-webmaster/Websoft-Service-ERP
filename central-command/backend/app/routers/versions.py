"""Version Control — manage ERP releases and push upgrades to clients."""
from datetime import datetime, timezone

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy import desc
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import get_current_admin
from app.models.admin import AdminUser
from app.models.clients import Client, ClientStatus
from app.models.versions import ERPVersion, VersionStatus, ClientUpgradeLog
from app.models.push_logs import PushLog, PushType
from app.schemas import (
    ERPVersionCreate, ERPVersionUpdate, ERPVersionOut,
    ClientVersionInfo, UpgradeAction, UpgradeLogOut,
)

router = APIRouter(prefix="/api/versions", tags=["versions"])


@router.get("/", response_model=list[ERPVersionOut])
def list_versions(
    db: Session = Depends(get_db),
    admin: AdminUser = Depends(get_current_admin),
):
    return db.query(ERPVersion).order_by(desc(ERPVersion.created_at)).all()


@router.post("/", response_model=ERPVersionOut, status_code=status.HTTP_201_CREATED)
def create_version(
    body: ERPVersionCreate,
    db: Session = Depends(get_db),
    admin: AdminUser = Depends(get_current_admin),
):
    existing = db.query(ERPVersion).filter(
        ERPVersion.version_number == body.version_number
    ).first()
    if existing:
        raise HTTPException(400, f"Version {body.version_number} already exists")

    version = ERPVersion(
        version_number=body.version_number,
        alembic_head=body.alembic_head,
        release_notes=body.release_notes,
        created_by=admin.id,
    )
    db.add(version)
    db.commit()
    db.refresh(version)
    return version


@router.patch("/{version_id}", response_model=ERPVersionOut)
def update_version(
    version_id: str,
    body: ERPVersionUpdate,
    db: Session = Depends(get_db),
    admin: AdminUser = Depends(get_current_admin),
):
    version = db.get(ERPVersion, version_id)
    if not version:
        raise HTTPException(404, "Version not found")

    if body.version_number is not None:
        version.version_number = body.version_number
    if body.alembic_head is not None:
        version.alembic_head = body.alembic_head
    if body.release_notes is not None:
        version.release_notes = body.release_notes
    if body.status is not None:
        version.status = body.status
        if body.status == "released":
            version.released_at = datetime.now(timezone.utc)
    if body.is_latest is not None:
        if body.is_latest:
            # Clear is_latest from all other versions
            db.query(ERPVersion).filter(ERPVersion.id != version.id).update(
                {"is_latest": False}
            )
        version.is_latest = body.is_latest

    db.commit()
    db.refresh(version)
    return version


@router.delete("/{version_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_version(
    version_id: str,
    db: Session = Depends(get_db),
    admin: AdminUser = Depends(get_current_admin),
):
    version = db.get(ERPVersion, version_id)
    if not version:
        raise HTTPException(404, "Version not found")
    db.delete(version)
    db.commit()


@router.get("/clients", response_model=list[ClientVersionInfo])
def get_client_versions(
    db: Session = Depends(get_db),
    admin: AdminUser = Depends(get_current_admin),
):
    """Compare all clients' current Alembic head against known versions."""
    clients = db.query(Client).filter(Client.status != ClientStatus.DECOMMISSIONED).all()
    versions = db.query(ERPVersion).filter(
        ERPVersion.status == VersionStatus.RELEASED
    ).all()
    latest = db.query(ERPVersion).filter(ERPVersion.is_latest == True).first()

    # Build a lookup: alembic_head -> version_number
    head_to_version = {v.alembic_head: v.version_number for v in versions}
    latest_version = latest.version_number if latest else None

    result = []
    for c in clients:
        current_version = head_to_version.get(c.last_known_alembic_head)
        is_up_to_date = (
            current_version == latest_version if current_version and latest_version
            else False
        )
        result.append(ClientVersionInfo(
            client_id=c.id,
            client_name=c.name,
            client_code=c.code,
            current_alembic_head=c.last_known_alembic_head,
            current_version=current_version,
            latest_version=latest_version,
            is_up_to_date=is_up_to_date,
            status=c.status.value,
        ))

    return result


@router.post("/clients/{client_id}/upgrade")
def upgrade_client(
    client_id: str,
    body: UpgradeAction,
    db: Session = Depends(get_db),
    admin: AdminUser = Depends(get_current_admin),
):
    """Push a version upgrade to a client's ERP database.

    This records the upgrade intent and updates the client's known
    Alembic head. The actual migration execution depends on the client's
    deployment setup (Alembic upgrade command run on the client side).
    Central Command records the push and updates the version tracking.
    """
    client = db.get(Client, client_id)
    if not client:
        raise HTTPException(404, "Client not found")

    version = db.get(ERPVersion, str(body.version_id))
    if not version:
        raise HTTPException(404, "Version not found")

    if version.status != VersionStatus.RELEASED:
        raise HTTPException(400, "Can only upgrade to a released version")

    from_version = client.last_known_alembic_head

    # Record the upgrade log
    log = ClientUpgradeLog(
        client_id=client.id,
        from_version=from_version,
        to_version=version.version_number,
        to_alembic_head=version.alembic_head,
        success=True,
        upgraded_by=admin.id,
    )
    db.add(log)

    # Update client's known version
    client.last_known_alembic_head = version.alembic_head
    client.last_connected_at = datetime.now(timezone.utc)

    # Push log
    db.add(PushLog(
        client_id=client.id,
        push_type=PushType.VERSION,
        detail=f"Upgraded to v{version.version_number} (head: {version.alembic_head})",
        success=True,
        pushed_by=admin.id,
    ))

    db.commit()
    db.refresh(log)

    return {
        "success": True,
        "client": client.code,
        "from_version": from_version,
        "to_version": version.version_number,
    }


@router.get("/upgrade-logs", response_model=list[UpgradeLogOut])
def list_upgrade_logs(
    client_id: str | None = None,
    db: Session = Depends(get_db),
    admin: AdminUser = Depends(get_current_admin),
):
    q = db.query(ClientUpgradeLog).order_by(desc(ClientUpgradeLog.upgraded_at))
    if client_id:
        q = q.filter(ClientUpgradeLog.client_id == client_id)
    return q.limit(100).all()
