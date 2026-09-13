"""Commission payouts: approval, clawback, payout mechanism (6.3-6.5)

Revision ID: e5f6a7b8c9d0
Revises: d4e5f6a7b8c9
Create Date: 2026-09-12

Adds:
- commission_payout_type enum (earning, clawback)
- commission_payout_status enum (draft, pending_approval, approved, paid, cancelled)
- commission_payouts table
- commission_payout value to document_entity_type enum
"""
from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "e5f6a7b8c9d0"
down_revision = "d4e5f6a7b8c9"
branch_labels = None
depends_on = None


def upgrade() -> None:
    # Create new enums
    payout_type = postgresql.ENUM(
        "earning", "clawback", name="commission_payout_type", create_type=False
    )
    payout_type.create(op.get_bind(), checkfirst=True)

    payout_status = postgresql.ENUM(
        "draft", "pending_approval", "approved", "paid", "cancelled",
        name="commission_payout_status", create_type=False,
    )
    payout_status.create(op.get_bind(), checkfirst=True)

    # Add commission_payout to document_entity_type enum
    op.execute("ALTER TYPE document_entity_type ADD VALUE IF NOT EXISTS 'commission_payout'")

    # Create commission_payouts table
    op.create_table(
        "commission_payouts",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
        sa.Column("company_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("companies.id"), nullable=False),
        sa.Column("payout_number", sa.String(50), nullable=False, index=True),
        sa.Column(
            "payout_type",
            postgresql.ENUM("earning", "clawback", name="commission_payout_type", create_type=False),
            nullable=False,
            server_default="earning",
        ),
        sa.Column(
            "status",
            postgresql.ENUM("draft", "pending_approval", "approved", "paid", "cancelled", name="commission_payout_status", create_type=False),
            nullable=False,
            server_default="draft",
        ),
        sa.Column("sales_staff_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id"), nullable=False),
        sa.Column("period_month", sa.String(7), nullable=False),
        sa.Column("period_start", sa.Date, nullable=False),
        sa.Column("period_end", sa.Date, nullable=False),
        sa.Column("amount_sgd", sa.Numeric(12, 2), nullable=False),
        sa.Column("rate_percent", sa.Numeric(5, 2), nullable=False),
        sa.Column("clawback_invoice_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("invoices.id"), nullable=True),
        sa.Column("clawback_reason", sa.String(500), nullable=True),
        sa.Column("submitted_by_user_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id"), nullable=True),
        sa.Column("submitted_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("approved_by_user_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id"), nullable=True),
        sa.Column("approved_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("paid_date", sa.Date, nullable=True),
        sa.Column("paid_reference", sa.String(200), nullable=True),
        sa.Column("paid_by_user_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id"), nullable=True),
        sa.Column("notes", sa.Text, nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now()),
        sa.Column("updated_at", sa.DateTime(timezone=True), server_default=sa.func.now()),
    )


def downgrade() -> None:
    op.drop_table("commission_payouts")
    op.execute("DROP TYPE IF EXISTS commission_payout_status")
    op.execute("DROP TYPE IF EXISTS commission_payout_type")
    # Note: cannot remove enum value from document_entity_type in downgrade
