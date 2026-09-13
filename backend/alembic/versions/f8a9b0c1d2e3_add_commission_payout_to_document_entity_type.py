"""Add commission_payout to document_entity_type enum

Revision ID: f8a9b0c1d2e3
Revises: e5f6a7b8c9d0
Create Date: 2026-09-12

"""

from typing import Sequence, Union

from alembic import op

# revision identifiers, used by Alembic.
revision = "f8a9b0c1d2e3"
down_revision = "e5f6a7b8c9d0"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    # ALTER TYPE ... ADD VALUE is not transactional in PostgreSQL < 12;
    # run outside a transaction block with autocommit.
    op.execute("""
        DO $$ BEGIN
            IF NOT EXISTS (
                SELECT 1 FROM pg_enum
                WHERE enumtypid = 'document_entity_type'::regtype
                  AND enumlabel = 'commission_payout'
            ) THEN
                ALTER TYPE document_entity_type ADD VALUE 'commission_payout';
            END IF;
        END $$;
    """)


def downgrade() -> None:
    # PostgreSQL does not support removing enum values; skip.
    pass
