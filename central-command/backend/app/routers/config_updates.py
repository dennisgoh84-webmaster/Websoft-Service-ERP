"""Config update management + push to client DBs."""
from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.orm import Session, joinedload

from app.core.database import get_db
from app.core.deps import get_current_admin
from app.models.admin import AdminUser
from app.models.clients import Client
from app.models.config_updates import ConfigPushLog, ConfigUpdate, ConfigUpdateStatus
from app.schemas import ConfigUpdateCreate, ConfigUpdateOut, ConfigUpdateUpdate
from app.services.client_db import ClientDBError, push_config_sql

router = APIRouter(prefix="/api/config-updates", tags=["config-updates"])


@router.get("/", response_model=list[ConfigUpdateOut])
def list_config_updates(db: Session = Depends(get_db), _admin: AdminUser = Depends(get_current_admin)):
    return (
        db.query(ConfigUpdate)
        .options(joinedload(ConfigUpdate.push_logs))
        .order_by(ConfigUpdate.created_at.desc())
        .all()
    )


@router.get("/{update_id}", response_model=ConfigUpdateOut)
def get_config_update(update_id: str, db: Session = Depends(get_db), _admin: AdminUser = Depends(get_current_admin)):
    cu = db.query(ConfigUpdate).options(joinedload(ConfigUpdate.push_logs)).get(update_id)
    if not cu:
        raise HTTPException(404, "Config update not found")
    return cu


@router.post("/", response_model=ConfigUpdateOut, status_code=201)
def create_config_update(body: ConfigUpdateCreate, db: Session = Depends(get_db), _admin: AdminUser = Depends(get_current_admin)):
    cu = ConfigUpdate(**body.model_dump())
    db.add(cu)
    db.commit()
    db.refresh(cu)
    return cu


@router.patch("/{update_id}", response_model=ConfigUpdateOut)
def update_config_update(update_id: str, body: ConfigUpdateUpdate, db: Session = Depends(get_db), _admin: AdminUser = Depends(get_current_admin)):
    cu = db.get(ConfigUpdate, update_id)
    if not cu:
        raise HTTPException(404, "Config update not found")
    for k, v in body.model_dump(exclude_unset=True).items():
        if k == "status":
            v = ConfigUpdateStatus(v)
        setattr(cu, k, v)
    db.commit()
    db.refresh(cu)
    return cu


@router.post("/{update_id}/push")
def push_config_update(
    update_id: str,
    db: Session = Depends(get_db),
    admin: AdminUser = Depends(get_current_admin),
):
    """Push a config update to ALL active clients."""
    cu = db.get(ConfigUpdate, update_id)
    if not cu:
        raise HTTPException(404, "Config update not found")
    if cu.status == ConfigUpdateStatus.DRAFT:
        raise HTTPException(400, "Config update is still in draft — set status to 'ready' first")

    clients = db.query(Client).filter(Client.status == "active").all()
    results = []
    successes = 0

    for client in clients:
        try:
            push_config_sql(db, client, cu.sql_statement, admin_id=admin.id)
            db.add(ConfigPushLog(
                config_update_id=cu.id,
                client_id=client.id,
                success=True,
            ))
            results.append({"client": client.name, "success": True})
            successes += 1
        except ClientDBError as e:
            db.add(ConfigPushLog(
                config_update_id=cu.id,
                client_id=client.id,
                success=False,
                error_message=str(e),
            ))
            results.append({"client": client.name, "success": False, "error": str(e)})

    cu.status = (
        ConfigUpdateStatus.PUSHED if successes == len(clients)
        else ConfigUpdateStatus.PARTIAL if successes > 0
        else cu.status
    )
    db.commit()
    return {"results": results, "total": len(clients), "successes": successes}


@router.post("/{update_id}/push/{client_id}")
def push_config_to_client(
    update_id: str,
    client_id: str,
    db: Session = Depends(get_db),
    admin: AdminUser = Depends(get_current_admin),
):
    """Push a config update to a specific client."""
    cu = db.get(ConfigUpdate, update_id)
    if not cu:
        raise HTTPException(404, "Config update not found")
    client = db.get(Client, client_id)
    if not client:
        raise HTTPException(404, "Client not found")

    try:
        result = push_config_sql(db, client, cu.sql_statement, admin_id=admin.id)
        db.add(ConfigPushLog(
            config_update_id=cu.id,
            client_id=client.id,
            success=True,
        ))
        db.commit()
        return result
    except ClientDBError as e:
        db.add(ConfigPushLog(
            config_update_id=cu.id,
            client_id=client.id,
            success=False,
            error_message=str(e),
        ))
        db.commit()
        raise HTTPException(502, f"Push failed: {e}")
