"""Central Command dashboard — summary statistics."""
from fastapi import APIRouter, Depends
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import get_current_admin
from app.models.admin import AdminUser
from app.models.advertisements import Advertisement
from app.models.clients import Client, ClientStatus
from app.models.config_updates import ConfigUpdate, ConfigUpdateStatus
from app.models.push_logs import PushLog
from app.schemas import DashboardStats

router = APIRouter(prefix="/api/dashboard", tags=["dashboard"])


@router.get("/", response_model=DashboardStats)
def get_dashboard(db: Session = Depends(get_db), _admin: AdminUser = Depends(get_current_admin)):
    total_clients = db.query(Client).count()
    active_clients = db.query(Client).filter(Client.status == ClientStatus.ACTIVE).count()
    suspended_clients = db.query(Client).filter(Client.status == ClientStatus.SUSPENDED).count()

    total_ads = db.query(Advertisement).count()
    active_ads = db.query(Advertisement).filter(Advertisement.is_active.is_(True)).count()

    total_config = db.query(ConfigUpdate).count()
    pending = db.query(ConfigUpdate).filter(ConfigUpdate.status == ConfigUpdateStatus.READY).count()

    recent_pushes = (
        db.query(PushLog)
        .order_by(PushLog.pushed_at.desc())
        .limit(20)
        .all()
    )

    return DashboardStats(
        total_clients=total_clients,
        active_clients=active_clients,
        suspended_clients=suspended_clients,
        total_ads=total_ads,
        active_ads=active_ads,
        total_config_updates=total_config,
        pending_pushes=pending,
        recent_pushes=recent_pushes,
    )
