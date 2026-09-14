"""FastAPI dependencies: DB session (re-exported) and current-user auth."""
import uuid

from fastapi import Depends, HTTPException, status
from fastapi.security import OAuth2PasswordBearer
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.models.core import User
from app.models.portal import PortalUser
from app.services.auth import decode_access_token, decode_purpose_token

oauth2_scheme = OAuth2PasswordBearer(tokenUrl="/api/auth/login")


def get_current_user(
    token: str = Depends(oauth2_scheme), db: Session = Depends(get_db)
) -> User:
    credentials_exception = HTTPException(
        status_code=status.HTTP_401_UNAUTHORIZED,
        detail="Could not validate credentials",
        headers={"WWW-Authenticate": "Bearer"},
    )
    user_id = decode_access_token(token)
    if user_id is None:
        raise credentials_exception
    user = db.get(User, user_id)
    if user is None or not user.is_active:
        raise credentials_exception
    return user


# Customer Helpdesk Portal (PORTAL-001..004, docs/customer-portal-design.md
# §4). A separate OAuth2PasswordBearer instance only so Swagger/OpenAPI
# tooling points at the right login URL -- it still just reads the
# Authorization header, so any bearer token reaches this dependency and
# is then rejected unless it decodes with purpose="portal". This is the
# WHOLE security boundary between staff and customers: get_current_user
# above only ever accepts purpose="access" (see decode_access_token), so
# a portal token presented there fails, and a staff access token
# presented here fails too (decode_purpose_token requires purpose=="portal"
# exactly). Tested explicitly -- see docs/customer-portal-design.md §9.4.
portal_oauth2_scheme = OAuth2PasswordBearer(tokenUrl="/api/portal/auth/login")


def get_current_portal_user(
    token: str = Depends(portal_oauth2_scheme), db: Session = Depends(get_db)
) -> PortalUser:
    credentials_exception = HTTPException(
        status_code=status.HTTP_401_UNAUTHORIZED,
        detail="Could not validate credentials",
        headers={"WWW-Authenticate": "Bearer"},
    )
    data = decode_purpose_token(token, "portal")
    if not data:
        raise credentials_exception
    try:
        portal_user_id = uuid.UUID(data["sub"])
    except (KeyError, ValueError):
        raise credentials_exception
    portal_user = db.get(PortalUser, portal_user_id)
    if portal_user is None or not portal_user.is_active:
        raise credentials_exception
    # A customer archived after this token was issued must lose access
    # immediately, not just on their next login (PORTAL-004).
    if portal_user.contact is None or portal_user.contact.customer is None:
        raise credentials_exception
    if portal_user.contact.customer.is_archived:
        raise credentials_exception
    return portal_user
