"""add login_otps table

Revision ID: 8c24275f88c3
Revises:
Create Date: 2026-09-13 09:36:37.063570
"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa

revision: str = '8c24275f88c3'
down_revision: Union[str, None] = None
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table('login_otps',
        sa.Column('id', sa.UUID(), nullable=False),
        sa.Column('admin_user_id', sa.UUID(), nullable=False),
        sa.Column('otp_code', sa.String(length=10), nullable=False),
        sa.Column('purpose', sa.String(length=30), nullable=False),
        sa.Column('is_used', sa.Boolean(), nullable=False),
        sa.Column('expires_at', sa.DateTime(timezone=True), nullable=False),
        sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
        sa.PrimaryKeyConstraint('id')
    )


def downgrade() -> None:
    op.drop_table('login_otps')
