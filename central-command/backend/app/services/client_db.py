"""
Client database connector.

Connects to a client's PostgreSQL on demand, verifies Alembic version
compatibility, and executes push operations (ads, licenses, config).
Each connection is short-lived — opened for the push, then closed.
"""
import logging
from datetime import datetime, timezone

from sqlalchemy import create_engine, text
from sqlalchemy.orm import Session as CCSession

from app.core.config import settings
from app.models.clients import Client
from app.models.push_logs import PushLog, PushType

logger = logging.getLogger(__name__)


class ClientDBError(Exception):
    """Raised when a client DB operation fails."""


def _build_dsn(client: Client) -> str:
    """Build a PostgreSQL DSN from client connection details."""
    ssl_suffix = "?sslmode=require" if client.db_use_tls else ""
    return (
        f"postgresql+psycopg://{client.db_username}:{client.db_password}"
        f"@{client.db_host}:{client.db_port}/{client.db_name}{ssl_suffix}"
    )


def test_connection(client: Client) -> dict:
    """Test connectivity + read Alembic version + list companies."""
    try:
        engine = create_engine(_build_dsn(client), pool_pre_ping=True)
        with engine.connect() as conn:
            # Read Alembic version
            result = conn.execute(text(
                "SELECT version_num FROM alembic_version LIMIT 1"
            ))
            row = result.fetchone()
            alembic_head = row[0] if row else None

            # Read companies
            result = conn.execute(text(
                "SELECT id, name, registration_number FROM companies LIMIT 50"
            ))
            companies = [
                {"id": str(r[0]), "name": r[1], "registration_number": r[2]}
                for r in result.fetchall()
            ]

        engine.dispose()
        return {
            "success": True,
            "message": "Connected successfully",
            "alembic_head": alembic_head,
            "companies": companies,
        }
    except Exception as e:
        return {"success": False, "message": str(e)}


def _check_alembic_version(client: Client, engine) -> str | None:
    """Read Alembic version and check compatibility. Returns head or raises."""
    with engine.connect() as conn:
        result = conn.execute(text(
            "SELECT version_num FROM alembic_version LIMIT 1"
        ))
        row = result.fetchone()
        if not row:
            raise ClientDBError("No alembic_version found in client DB")
        return row[0]


def _log_push(
    cc_db: CCSession,
    client: Client,
    push_type: PushType,
    detail: str,
    success: bool,
    error: str | None = None,
    pushed_by=None,
):
    """Record a push attempt in Central Command's push_logs."""
    cc_db.add(PushLog(
        client_id=client.id,
        push_type=push_type,
        detail=detail,
        success=success,
        error_message=error,
        pushed_by=pushed_by,
    ))
    cc_db.flush()


def push_announcements(
    cc_db: CCSession,
    client: Client,
    announcements: list[dict],
    admin_id=None,
) -> dict:
    """Push announcement rows into a client's `announcements` table.

    Uses UPSERT (INSERT ON CONFLICT UPDATE) keyed on announcement id.
    """
    try:
        engine = create_engine(_build_dsn(client), pool_pre_ping=True)
        alembic_head = _check_alembic_version(client, engine)

        with engine.begin() as conn:
            for ann in announcements:
                conn.execute(text("""
                    INSERT INTO announcements (id, tag, text, sort_order, is_active, created_at)
                    VALUES (:id, :tag, :text, :sort_order, :is_active, NOW())
                    ON CONFLICT (id) DO UPDATE SET
                        tag = EXCLUDED.tag,
                        text = EXCLUDED.text,
                        sort_order = EXCLUDED.sort_order,
                        is_active = EXCLUDED.is_active
                """), ann)

        engine.dispose()

        # Update client's last connection info
        client.last_connected_at = datetime.now(timezone.utc)
        client.last_known_alembic_head = alembic_head

        _log_push(cc_db, client, PushType.ADVERTISEMENT,
                  f"Pushed {len(announcements)} announcement(s)",
                  True, pushed_by=admin_id)

        return {"success": True, "count": len(announcements)}

    except Exception as e:
        _log_push(cc_db, client, PushType.ADVERTISEMENT,
                  "Push failed", False, str(e), pushed_by=admin_id)
        raise ClientDBError(str(e))


def push_video_url(
    cc_db: CCSession,
    client: Client,
    video_url: str | None,
    admin_id=None,
) -> dict:
    """Update the ad_banner_settings singleton row in a client DB."""
    try:
        engine = create_engine(_build_dsn(client), pool_pre_ping=True)
        _check_alembic_version(client, engine)

        with engine.begin() as conn:
            conn.execute(text("""
                UPDATE ad_banner_settings
                SET video_url = :video_url, updated_at = NOW()
                WHERE id = 1
            """), {"video_url": video_url})

        engine.dispose()
        client.last_connected_at = datetime.now(timezone.utc)

        _log_push(cc_db, client, PushType.VIDEO,
                  f"Set video_url={'(cleared)' if not video_url else video_url[:80]}",
                  True, pushed_by=admin_id)

        return {"success": True}

    except Exception as e:
        _log_push(cc_db, client, PushType.VIDEO,
                  "Push failed", False, str(e), pushed_by=admin_id)
        raise ClientDBError(str(e))


def push_license_change(
    cc_db: CCSession,
    client: Client,
    company_id: str,
    module_key: str,
    enabled: bool,
    license_type: str | None = None,
    notes: str | None = None,
    admin_id=None,
) -> dict:
    """Enable or disable a module for a company in the client DB."""
    try:
        engine = create_engine(_build_dsn(client), pool_pre_ping=True)
        _check_alembic_version(client, engine)

        with engine.begin() as conn:
            # Check if module exists in client DB
            result = conn.execute(text(
                "SELECT key FROM modules WHERE key = :key"
            ), {"key": module_key})
            if not result.fetchone():
                raise ClientDBError(f"Module '{module_key}' not found in client DB")

            # Upsert company_modules
            conn.execute(text("""
                INSERT INTO company_modules (id, company_id, module_key, enabled,
                    license_type, notes, enabled_at, updated_at)
                VALUES (gen_random_uuid(), :company_id::uuid, :module_key, :enabled,
                    :license_type, :notes,
                    CASE WHEN :enabled THEN NOW() ELSE NULL END, NOW())
                ON CONFLICT ON CONSTRAINT uq_company_module DO UPDATE SET
                    enabled = EXCLUDED.enabled,
                    license_type = COALESCE(EXCLUDED.license_type, company_modules.license_type),
                    notes = COALESCE(EXCLUDED.notes, company_modules.notes),
                    enabled_at = CASE WHEN EXCLUDED.enabled AND NOT company_modules.enabled
                                 THEN NOW() ELSE company_modules.enabled_at END,
                    updated_at = NOW()
            """), {
                "company_id": company_id,
                "module_key": module_key,
                "enabled": enabled,
                "license_type": license_type or "included",
                "notes": notes,
            })

        engine.dispose()
        client.last_connected_at = datetime.now(timezone.utc)

        action = "enabled" if enabled else "disabled"
        _log_push(cc_db, client, PushType.LICENSE,
                  f"{action} module '{module_key}' for company {company_id}",
                  True, pushed_by=admin_id)

        return {"success": True, "action": action}

    except Exception as e:
        _log_push(cc_db, client, PushType.LICENSE,
                  "Push failed", False, str(e), pushed_by=admin_id)
        raise ClientDBError(str(e))


def push_config_sql(
    cc_db: CCSession,
    client: Client,
    sql_statement: str,
    admin_id=None,
) -> dict:
    """Execute a config update SQL statement on a client DB."""
    try:
        engine = create_engine(_build_dsn(client), pool_pre_ping=True)
        _check_alembic_version(client, engine)

        with engine.begin() as conn:
            result = conn.execute(text(sql_statement))
            rowcount = result.rowcount

        engine.dispose()
        client.last_connected_at = datetime.now(timezone.utc)

        _log_push(cc_db, client, PushType.CONFIG,
                  f"Config SQL executed, {rowcount} row(s) affected",
                  True, pushed_by=admin_id)

        return {"success": True, "rows_affected": rowcount}

    except Exception as e:
        _log_push(cc_db, client, PushType.CONFIG,
                  "Push failed", False, str(e), pushed_by=admin_id)
        raise ClientDBError(str(e))


def push_license_limit(
    cc_db: CCSession,
    client: Client,
    max_licenses: int | None,
    admin_id=None,
) -> dict:
    """Push the concurrent-login license limit to a client DB.

    Writes to the ``license_settings`` table (UPSERT).  If the table
    does not exist yet in the client schema the push creates it
    on the fly — a single key/value row keyed ``max_concurrent_logins``.
    """
    try:
        engine = create_engine(_build_dsn(client), pool_pre_ping=True)
        _check_alembic_version(client, engine)

        with engine.begin() as conn:
            # Ensure table exists (idempotent)
            conn.execute(text("""
                CREATE TABLE IF NOT EXISTS license_settings (
                    key   VARCHAR(100) PRIMARY KEY,
                    value VARCHAR(255),
                    updated_at TIMESTAMPTZ DEFAULT NOW()
                )
            """))
            # Upsert the limit
            val = str(max_licenses) if max_licenses is not None else None
            conn.execute(text("""
                INSERT INTO license_settings (key, value, updated_at)
                VALUES ('max_concurrent_logins', :val, NOW())
                ON CONFLICT (key) DO UPDATE SET
                    value = EXCLUDED.value,
                    updated_at = NOW()
            """), {"val": val})

        engine.dispose()
        client.last_connected_at = datetime.now(timezone.utc)

        label = str(max_licenses) if max_licenses else "unlimited"
        _log_push(cc_db, client, PushType.LICENSE,
                  f"Set max concurrent logins to {label}",
                  True, pushed_by=admin_id)

        return {"success": True, "max_licenses": max_licenses}

    except Exception as e:
        _log_push(cc_db, client, PushType.LICENSE,
                  "Push license limit failed", False, str(e),
                  pushed_by=admin_id)
        raise ClientDBError(str(e))


def read_client_modules(client: Client) -> list[dict]:
    """Read modules + company_modules from a client DB for license overview."""
    try:
        engine = create_engine(_build_dsn(client), pool_pre_ping=True)
        with engine.connect() as conn:
            result = conn.execute(text("""
                SELECT m.key, m.name, m.is_built,
                       cm.enabled, cm.license_type, cm.notes, cm.updated_at,
                       c.id as company_id, c.name as company_name
                FROM modules m
                CROSS JOIN companies c
                LEFT JOIN company_modules cm
                    ON cm.module_key = m.key AND cm.company_id = c.id
                ORDER BY c.name, m.key
            """))
            rows = [
                {
                    "module_key": r[0],
                    "module_name": r[1],
                    "is_built": r[2],
                    "enabled": r[3] if r[3] is not None else False,
                    "license_type": r[4] or "included",
                    "notes": r[5],
                    "updated_at": r[6].isoformat() if r[6] else None,
                    "company_id": str(r[7]),
                    "company_name": r[8],
                }
                for r in result.fetchall()
            ]
        engine.dispose()
        return rows
    except Exception as e:
        raise ClientDBError(str(e))
