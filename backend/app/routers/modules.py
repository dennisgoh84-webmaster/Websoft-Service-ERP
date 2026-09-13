"""
Module access check -- used by the frontend nav to determine which
modules the current user can see, and a read-only catalog used by
Group Authority setup.  Module management (enable/disable) has moved
to Central Command (Client Control).
"""
from fastapi import APIRouter, Depends
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import get_current_user
from app.models.core import User, UserRole
from app.models.groups import AccessLevel
from app.models.licensing import CompanyModule, Module
from app.schemas.schemas import ModuleOut
from app.services.authority import has_access

router = APIRouter(prefix="/api/modules", tags=["modules"])


@router.get("/my-access", response_model=dict[str, bool])
def my_module_access(
    db: Session = Depends(get_db),
    current_user: User = Depends(get_current_user),
):
    """Which built modules the current user can actually reach right now
    in their active company -- Group Authority AND module enablement both
    have to say yes (see app/services/authority.py). Used by the
    frontend nav to hide links the user has no access to, rather than
    showing a link that immediately 403s. Not itself gated by a module
    check: the app shell needs this before it knows what the user can
    see at all.

    Module management (toggle on/off, license type) is handled from
    Central Command → Client Control, not from within the ERP."""
    modules = db.query(Module).filter(Module.is_built.is_(True)).all()
    company_modules = {
        cm.module_key: cm
        for cm in db.query(CompanyModule).filter(CompanyModule.company_id == current_user.company_id)
    }
    result: dict[str, bool] = {}
    for m in modules:
        if current_user.role == UserRole.OWNER:
            # Owner bypasses both checks (see require_module_access) --
            # nav visibility mirrors that so a link he can actually use
            # isn't hidden from him.
            result[m.key] = True
            continue
        cm = company_modules.get(m.key)
        result[m.key] = bool(cm and cm.enabled) and has_access(db, current_user, m.key, AccessLevel.VIEW)
    return result


@router.get("", response_model=list[ModuleOut])
def list_modules(
    db: Session = Depends(get_db),
    current_user: User = Depends(get_current_user),
):
    """Read-only module catalog. Used by Group Authority setup to show
    which modules exist so permissions can be assigned.  Module
    enable/disable has moved to Central Command → Client Control."""
    modules = db.query(Module).order_by(Module.key).all()
    company_modules = {
        cm.module_key: cm
        for cm in db.query(CompanyModule).filter(CompanyModule.company_id == current_user.company_id)
    }
    out = []
    for m in modules:
        cm = company_modules.get(m.key)
        out.append(
            ModuleOut(
                key=m.key,
                name=m.name,
                description=m.description,
                is_built=m.is_built,
                enabled=cm.enabled if cm else False,
                license_type=cm.license_type if cm else "included",
            )
        )
    return out
