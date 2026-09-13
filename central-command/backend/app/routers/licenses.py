"""License management — view and toggle module licenses on client DBs."""
from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import get_current_admin
from app.models.admin import AdminUser
from app.models.clients import Client
from app.schemas import LicenseAction
from app.services.client_db import (
    ClientDBError,
    push_license_change,
    push_license_limit,
    read_client_modules,
)

router = APIRouter(prefix="/api/licenses", tags=["licenses"])


@router.get("/{client_id}/modules")
def get_client_modules(
    client_id: str,
    db: Session = Depends(get_db),
    _admin: AdminUser = Depends(get_current_admin),
):
    """Read all modules + their enabled state from a client DB."""
    client = db.get(Client, client_id)
    if not client:
        raise HTTPException(404, "Client not found")
    try:
        return read_client_modules(client)
    except ClientDBError as e:
        raise HTTPException(502, f"Client DB error: {e}")


@router.post("/{client_id}/modules")
def set_module_license(
    client_id: str,
    body: LicenseAction,
    db: Session = Depends(get_db),
    admin: AdminUser = Depends(get_current_admin),
):
    """Enable or disable a module for a company in the client DB."""
    client = db.get(Client, client_id)
    if not client:
        raise HTTPException(404, "Client not found")

    # We need the company_id from the client DB — passed as query param
    # or default to the first company
    try:
        modules = read_client_modules(client)
        if not modules:
            raise HTTPException(400, "No companies found in client DB")
        company_id = modules[0]["company_id"]

        result = push_license_change(
            db, client,
            company_id=company_id,
            module_key=body.module_key,
            enabled=body.enabled,
            license_type=body.license_type,
            notes=body.notes,
            admin_id=admin.id,
        )
        db.commit()
        return result
    except ClientDBError as e:
        raise HTTPException(502, f"Client DB error: {e}")


@router.post("/{client_id}/companies/{company_id}/modules")
def set_company_module_license(
    client_id: str,
    company_id: str,
    body: LicenseAction,
    db: Session = Depends(get_db),
    admin: AdminUser = Depends(get_current_admin),
):
    """Enable or disable a specific module for a specific company."""
    client = db.get(Client, client_id)
    if not client:
        raise HTTPException(404, "Client not found")
    try:
        result = push_license_change(
            db, client,
            company_id=company_id,
            module_key=body.module_key,
            enabled=body.enabled,
            license_type=body.license_type,
            notes=body.notes,
            admin_id=admin.id,
        )
        db.commit()
        return result
    except ClientDBError as e:
        raise HTTPException(502, f"Client DB error: {e}")


# ── Concurrent-login license limit ───────────────────────────────


class LicenseLimitUpdate(BaseModel):
    """Set the max concurrent logins for a client (null = unlimited)."""
    max_licenses: int | None = None


@router.patch("/{client_id}/license-limit")
def update_license_limit(
    client_id: str,
    body: LicenseLimitUpdate,
    db: Session = Depends(get_db),
    _admin: AdminUser = Depends(get_current_admin),
):
    """Set the max concurrent-login limit on a client (CC-side only)."""
    client = db.get(Client, client_id)
    if not client:
        raise HTTPException(404, "Client not found")
    client.max_licenses = body.max_licenses
    db.commit()
    db.refresh(client)
    return {
        "success": True,
        "client_code": client.code,
        "max_licenses": client.max_licenses,
    }


@router.post("/{client_id}/license-limit/push")
def push_client_license_limit(
    client_id: str,
    db: Session = Depends(get_db),
    admin: AdminUser = Depends(get_current_admin),
):
    """Push the current max_licenses value to the client's database."""
    client = db.get(Client, client_id)
    if not client:
        raise HTTPException(404, "Client not found")
    try:
        result = push_license_limit(
            db, client,
            max_licenses=client.max_licenses,
            admin_id=admin.id,
        )
        db.commit()
        return result
    except ClientDBError as e:
        raise HTTPException(502, f"Client DB error: {e}")
