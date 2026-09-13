"""Alembic env for Central Command's own database."""
from logging.config import fileConfig

from alembic import context
from sqlalchemy import engine_from_config, pool

from app.core.database import Base
# Import all models so metadata.tables is populated
from app.models.admin import AdminUser  # noqa: F401
from app.models.clients import Client  # noqa: F401
from app.models.advertisements import Advertisement, AdAssignment, VideoSetting, VideoAssignment  # noqa: F401
from app.models.config_updates import ConfigUpdate, ConfigPushLog  # noqa: F401
from app.models.push_logs import PushLog  # noqa: F401
from app.models.versions import ERPVersion, ClientUpgradeLog  # noqa: F401
from app.models.staff import SupportLogin  # noqa: F401
from app.models.login_otp import LoginOTP  # noqa: F401

config = context.config
if config.config_file_name is not None:
    fileConfig(config.config_file_name)

target_metadata = Base.metadata


def run_migrations_offline() -> None:
    url = config.get_main_option("sqlalchemy.url")
    context.configure(url=url, target_metadata=target_metadata, literal_binds=True)
    with context.begin_transaction():
        context.run_migrations()


def run_migrations_online() -> None:
    connectable = engine_from_config(
        config.get_section(config.config_ini_section, {}),
        prefix="sqlalchemy.",
        poolclass=pool.NullPool,
    )
    with connectable.connect() as connection:
        context.configure(connection=connection, target_metadata=target_metadata)
        with context.begin_transaction():
            context.run_migrations()


if context.is_offline_mode():
    run_migrations_offline()
else:
    run_migrations_online()
