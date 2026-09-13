"""period lock matrix

Revision ID: b2c3d4e5f6a7
Revises: a1b2c3d4e5f6
Create Date: 2026-09-12 16:00:00.000000

"""
from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql
from sqlalchemy.dialects.postgresql import UUID


# revision identifiers, used by Alembic.
revision = "b2c3d4e5f6a7"
down_revision = "a1b2c3d4e5f6"
branch_labels = None
depends_on = None

# The valid doc-type × operation combinations (mirrored from
# app/models/periods.py VALID_DOC_OPERATIONS).
VALID_COMBOS = [
    ("SALES_INVOICE", "UPDATE"), ("SALES_INVOICE", "REVERSE"),
    ("SALES_INVOICE", "GL"), ("SALES_INVOICE", "UNGL"),
    ("RECEIPT_VOUCHER", "UPDATE"), ("RECEIPT_VOUCHER", "REVERSE"),
    ("RECEIPT_VOUCHER", "BANK"), ("RECEIPT_VOUCHER", "UNBANK"),
    ("RECEIPT_VOUCHER", "GL"), ("RECEIPT_VOUCHER", "UNGL"),
    ("PAYMENT_VOUCHER", "UPDATE"), ("PAYMENT_VOUCHER", "REVERSE"),
    ("PAYMENT_VOUCHER", "BANK"), ("PAYMENT_VOUCHER", "UNBANK"),
    ("PAYMENT_VOUCHER", "GL"), ("PAYMENT_VOUCHER", "UNGL"),
    ("PURCHASE_BILL", "UPDATE"), ("PURCHASE_BILL", "REVERSE"),
    ("PURCHASE_BILL", "GL"), ("PURCHASE_BILL", "UNGL"),
    ("JOURNAL_VOUCHER", "UPDATE"), ("JOURNAL_VOUCHER", "REVERSE"),
    ("JOURNAL_VOUCHER", "GL"), ("JOURNAL_VOUCHER", "UNGL"),
]


def upgrade() -> None:
    # Create enums first
    # Use raw SQL to create enums so SQLAlchemy's create_table
    # doesn't try to re-create them via before_create events.
    op.execute("""
        DO $$ BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'period_doc_type') THEN
                CREATE TYPE period_doc_type AS ENUM (
                    'SALES_INVOICE', 'RECEIPT_VOUCHER', 'PAYMENT_VOUCHER',
                    'PURCHASE_BILL', 'JOURNAL_VOUCHER'
                );
            END IF;
        END $$;
    """)
    op.execute("""
        DO $$ BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'period_operation') THEN
                CREATE TYPE period_operation AS ENUM (
                    'UPDATE', 'REVERSE', 'BANK', 'UNBANK', 'GL', 'UNGL'
                );
            END IF;
        END $$;
    """)

    op.create_table(
        "period_locks",
        sa.Column("id", UUID(as_uuid=True), primary_key=True, server_default=sa.text("gen_random_uuid()")),
        sa.Column("period_id", UUID(as_uuid=True), sa.ForeignKey("accounting_periods.id"), nullable=False),
        sa.Column("doc_type", postgresql.ENUM("SALES_INVOICE", "RECEIPT_VOUCHER", "PAYMENT_VOUCHER", "PURCHASE_BILL", "JOURNAL_VOUCHER", name="period_doc_type", create_type=False), nullable=False),
        sa.Column("operation", postgresql.ENUM("UPDATE", "REVERSE", "BANK", "UNBANK", "GL", "UNGL", name="period_operation", create_type=False), nullable=False),
        sa.Column("is_locked", sa.Boolean(), nullable=False, server_default=sa.text("false")),
        sa.Column("locked_by_user_id", UUID(as_uuid=True), sa.ForeignKey("users.id"), nullable=True),
        sa.Column("locked_at", sa.DateTime(timezone=True), nullable=True),
        sa.UniqueConstraint("period_id", "doc_type", "operation", name="uq_period_lock"),
    )

    # Seed lock rows for every existing period.
    # OPEN periods get is_locked=false; CLOSED periods get is_locked=true.
    conn = op.get_bind()
    rows = conn.execute(sa.text("SELECT id, status FROM accounting_periods")).fetchall()
    for period_id, status in rows:
        locked = status == "CLOSED"
        for doc_type, operation in VALID_COMBOS:
            conn.execute(sa.text(
                "INSERT INTO period_locks (id, period_id, doc_type, operation, is_locked) "
                "VALUES (gen_random_uuid(), :pid, :dt, :op, :lk)"
            ), {"pid": period_id, "dt": doc_type, "op": operation, "lk": locked})


def downgrade() -> None:
    op.drop_table("period_locks")
    sa.Enum(name="period_operation").drop(op.get_bind(), checkfirst=True)
    sa.Enum(name="period_doc_type").drop(op.get_bind(), checkfirst=True)
