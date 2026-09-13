"""
Central Command application configuration.

Values are read from environment variables (or a local .env file).
Central Command has its OWN PostgreSQL database (separate from any
client ERP database).
"""
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8")

    app_name: str = "Web Master Central Command"
    environment: str = "development"

    # Central Command's own database (NOT a client DB)
    database_url: str = (
        "postgresql+psycopg://cc_app:cc_dev_local@localhost:5432/central_command"
    )

    # JWT for admin login
    jwt_secret_key: str = "dev-only-secret-do-not-use-in-production"
    jwt_algorithm: str = "HS256"
    access_token_expire_minutes: int = 60 * 8

    # Minimum Alembic migration head the client DB must be at for
    # Central Command to write to it.  Updated whenever the client-side
    # schema contract changes.
    min_client_alembic_head: str = "b2c3d4e5f6a7"


settings = Settings()
