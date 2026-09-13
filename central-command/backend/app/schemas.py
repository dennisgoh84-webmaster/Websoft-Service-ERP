"""Pydantic schemas for Central Command API."""
import uuid
from datetime import datetime

from pydantic import BaseModel, ConfigDict


# ── Auth ──────────────────────────────────────────────────────────────
class LoginRequest(BaseModel):
    username: str
    password: str


class TokenResponse(BaseModel):
    access_token: str
    token_type: str = "bearer"
    full_name: str


class LoginResponse(BaseModel):
    """Returned by /auth/login — may be a token (dev bypass) or OTP-required."""
    status: str  # "ok" | "otp_required"
    access_token: str | None = None
    token_type: str = "bearer"
    full_name: str | None = None
    otp_session: str | None = None  # opaque session id for verify-otp
    # Dev mode only: the OTP code itself, so testing works without email
    _dev_otp: str | None = None


class VerifyOTPRequest(BaseModel):
    otp_session: str
    otp_code: str


class ForgotPasswordRequest(BaseModel):
    username: str


class ResetPasswordRequest(BaseModel):
    username: str
    otp_code: str
    new_password: str


class ForgotUsernameRequest(BaseModel):
    email: str


class AdminUserOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    username: str
    full_name: str
    email: str | None = None
    role: str = "admin"
    is_active: bool
    created_at: datetime | None = None


# ── Clients ───────────────────────────────────────────────────────────
class ClientCreate(BaseModel):
    name: str
    code: str
    db_host: str
    db_port: int = 5432
    db_name: str
    db_username: str
    db_password: str
    db_use_tls: bool = True
    notes: str | None = None
    max_licenses: int | None = None


class ClientUpdate(BaseModel):
    name: str | None = None
    db_host: str | None = None
    db_port: int | None = None
    db_name: str | None = None
    db_username: str | None = None
    db_password: str | None = None
    db_use_tls: bool | None = None
    status: str | None = None
    notes: str | None = None
    max_licenses: int | None = None


class ClientOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    name: str
    code: str
    db_host: str
    db_port: int
    db_name: str
    db_username: str
    db_use_tls: bool
    status: str
    notes: str | None
    max_licenses: int | None = None
    last_connected_at: datetime | None
    last_known_alembic_head: str | None
    created_at: datetime
    updated_at: datetime


class ClientSummary(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    name: str
    code: str
    status: str
    max_licenses: int | None = None
    last_connected_at: datetime | None
    last_known_alembic_head: str | None


class ConnectionTestResult(BaseModel):
    success: bool
    message: str
    alembic_head: str | None = None
    companies: list[dict] | None = None


# ── Advertisements ────────────────────────────────────────────────────
class AdvertisementCreate(BaseModel):
    tag: str | None = None
    text: str
    sort_order: int = 0
    is_active: bool = True
    client_ids: list[uuid.UUID] = []


class AdvertisementUpdate(BaseModel):
    tag: str | None = None
    text: str | None = None
    sort_order: int | None = None
    is_active: bool | None = None
    client_ids: list[uuid.UUID] | None = None


class AdAssignmentOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    client_id: uuid.UUID
    pushed_at: datetime | None


class AdvertisementOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    tag: str | None
    text: str
    sort_order: int
    is_active: bool
    created_at: datetime
    assignments: list[AdAssignmentOut] = []


# ── Video Settings ────────────────────────────────────────────────────
class VideoSettingCreate(BaseModel):
    video_url: str | None = None
    label: str = "Default"
    client_ids: list[uuid.UUID] = []


class VideoSettingOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    video_url: str | None
    label: str
    is_active: bool
    created_at: datetime


# ── License Management ────────────────────────────────────────────────
class LicenseAction(BaseModel):
    """Set a module's enabled state on a client."""
    module_key: str
    enabled: bool
    license_type: str | None = None
    notes: str | None = None


class ClientModuleOut(BaseModel):
    module_key: str
    module_name: str
    enabled: bool
    license_type: str
    notes: str | None
    updated_at: datetime | None


# ── Config Updates ────────────────────────────────────────────────────
class ConfigUpdateCreate(BaseModel):
    title: str
    description: str | None = None
    sql_statement: str


class ConfigUpdateUpdate(BaseModel):
    title: str | None = None
    description: str | None = None
    sql_statement: str | None = None
    status: str | None = None


class ConfigPushLogOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    client_id: uuid.UUID
    success: bool
    error_message: str | None
    pushed_at: datetime


class ConfigUpdateOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    title: str
    description: str | None
    sql_statement: str
    status: str
    created_at: datetime
    updated_at: datetime
    push_logs: list[ConfigPushLogOut] = []


# ── Push Logs ─────────────────────────────────────────────────────────
class PushLogOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    client_id: uuid.UUID
    push_type: str
    detail: str
    success: bool
    error_message: str | None
    pushed_at: datetime
    pushed_by: uuid.UUID | None


# ── Dashboard ─────────────────────────────────────────────────────────
class DashboardStats(BaseModel):
    total_clients: int
    active_clients: int
    suspended_clients: int
    total_ads: int
    active_ads: int
    total_config_updates: int
    pending_pushes: int
    recent_pushes: list[PushLogOut]


# ── Staff Management ─────────────────────────────────────────────────
class StaffCreate(BaseModel):
    username: str
    full_name: str
    email: str | None = None
    password: str
    role: str = "admin"


class StaffUpdate(BaseModel):
    full_name: str | None = None
    email: str | None = None
    role: str | None = None
    is_active: bool | None = None
    password: str | None = None


class SupportLoginPush(BaseModel):
    """Push a support login to a client ERP database."""
    client_id: uuid.UUID
    admin_user_id: uuid.UUID
    login_email: str
    login_password: str
    reason: str | None = None


class SupportLoginOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    admin_user_id: uuid.UUID
    client_id: uuid.UUID
    login_email: str
    client_user_id: str | None
    status: str
    reason: str | None
    pushed_at: datetime
    pushed_by: uuid.UUID | None
    revoked_at: datetime | None


# ── Version Control ──────────────────────────────────────────────────
class ERPVersionCreate(BaseModel):
    version_number: str
    alembic_head: str
    release_notes: str | None = None


class ERPVersionUpdate(BaseModel):
    version_number: str | None = None
    alembic_head: str | None = None
    release_notes: str | None = None
    status: str | None = None
    is_latest: bool | None = None


class ERPVersionOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    version_number: str
    alembic_head: str
    release_notes: str | None
    status: str
    is_latest: bool
    released_at: datetime | None
    created_at: datetime


class ClientVersionInfo(BaseModel):
    """A client's current version status compared to the latest."""
    client_id: uuid.UUID
    client_name: str
    client_code: str
    current_alembic_head: str | None
    current_version: str | None
    latest_version: str | None
    is_up_to_date: bool
    status: str


class UpgradeAction(BaseModel):
    """Push an upgrade to a client."""
    version_id: uuid.UUID


class UpgradeLogOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    client_id: uuid.UUID
    from_version: str | None
    to_version: str
    to_alembic_head: str
    success: bool
    error_message: str | None
    upgraded_at: datetime
    upgraded_by: uuid.UUID | None
