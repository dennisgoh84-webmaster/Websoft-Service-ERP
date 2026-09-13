"""
SQLAlchemy engine/session for Central Command's OWN database.

Client ERP databases are connected to on-demand via
app.services.client_db — not through this module.
"""
from collections.abc import Generator

from sqlalchemy import create_engine
from sqlalchemy.orm import DeclarativeBase, Session, sessionmaker

from app.core.config import settings

engine = create_engine(settings.database_url, pool_pre_ping=True)
SessionLocal = sessionmaker(autocommit=False, autoflush=False, bind=engine)


class Base(DeclarativeBase):
    """Base class for Central Command's own ORM models."""


def get_db() -> Generator[Session, None, None]:
    """FastAPI dependency — yields a session to Central Command's DB."""
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()
