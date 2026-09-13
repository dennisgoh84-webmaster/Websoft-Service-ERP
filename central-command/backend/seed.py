"""
Seed Central Command's own database with a default admin user.

Run:  cd central-command/backend && uv run python seed.py
"""
from app.core.database import Base, engine, SessionLocal
from app.models.admin import AdminUser
from app.models.clients import Client  # noqa: F401 — register models
from app.models.advertisements import Advertisement, AdAssignment, VideoSetting, VideoAssignment  # noqa: F401
from app.models.config_updates import ConfigUpdate, ConfigPushLog  # noqa: F401
from app.models.push_logs import PushLog  # noqa: F401
from app.models.versions import ERPVersion, ClientUpgradeLog  # noqa: F401
from app.models.staff import SupportLogin  # noqa: F401
from app.models.login_otp import LoginOTP  # noqa: F401
from app.services.auth import hash_password


def seed():
    Base.metadata.create_all(bind=engine)
    db = SessionLocal()

    # Default admin (super_admin role)
    existing = db.query(AdminUser).filter(AdminUser.username == "admin").first()
    if not existing:
        db.add(AdminUser(
            username="admin",
            full_name="Dennis Goh",
            email="admin@webmaster.com.sg",
            hashed_password=hash_password("Admin123"),
            role="super_admin",
        ))
        print("Created admin user: admin / Admin123 (super_admin)")
    else:
        # Upgrade existing admin to super_admin if needed
        if existing.role != "super_admin":
            existing.role = "super_admin"
            print("Upgraded admin to super_admin role")
        if not existing.email:
            existing.email = "admin@webmaster.com.sg"
        print("Admin user already exists")

    db.commit()
    db.close()
    print("Central Command seed complete.")


if __name__ == "__main__":
    seed()
