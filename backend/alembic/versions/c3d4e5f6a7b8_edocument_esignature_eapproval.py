"""eDocument attachments, eSignature, and eApproval Master

Revision ID: c3d4e5f6a7b8
Revises: b2c3d4e5f6a7
Create Date: 2026-09-12

Creates:
- document_entity_type enum (shared by attachments, signatures, approvals)
- document_attachments table
- document_signatures table
- approval_mode enum
- approval_status enum
- approval_decision_value enum
- approval_authorities table
- approval_authority_members table
- approval_rules table
- approval_requests table
- approval_decisions table
"""
from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql
from sqlalchemy.dialects.postgresql import UUID


revision = "c3d4e5f6a7b8"
down_revision = "b2c3d4e5f6a7"
branch_labels = None
depends_on = None

# Enum values
DOCUMENT_ENTITY_TYPES = [
    "quotation", "invoice", "receipt_voucher", "payment_voucher",
    "purchase_order", "supplier_invoice", "journal_entry",
    "job_order", "service_record", "contract", "incident",
]
APPROVAL_MODES = ["any_one", "all_must"]
APPROVAL_STATUSES = ["pending", "approved", "rejected"]
APPROVAL_DECISION_VALUES = ["approved", "rejected"]


def upgrade() -> None:
    # ── Enums (all use raw SQL with IF NOT EXISTS) ─────────────────
    op.execute("""
        DO $$ BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'document_entity_type') THEN
                CREATE TYPE document_entity_type AS ENUM (
                    'quotation', 'invoice', 'receipt_voucher', 'payment_voucher',
                    'purchase_order', 'supplier_invoice', 'journal_entry',
                    'job_order', 'service_record', 'contract', 'incident'
                );
            END IF;
        END $$;
    """)
    op.execute("""
        DO $$ BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'approval_mode') THEN
                CREATE TYPE approval_mode AS ENUM ('any_one', 'all_must');
            END IF;
        END $$;
    """)
    op.execute("""
        DO $$ BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'approval_status') THEN
                CREATE TYPE approval_status AS ENUM ('pending', 'approved', 'rejected');
            END IF;
        END $$;
    """)
    op.execute("""
        DO $$ BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'approval_decision_value') THEN
                CREATE TYPE approval_decision_value AS ENUM ('approved', 'rejected');
            END IF;
        END $$;
    """)

    # Column type helpers — postgresql.ENUM with create_type=False
    # prevents SQLAlchemy from trying to re-CREATE the type.
    det = postgresql.ENUM(*DOCUMENT_ENTITY_TYPES, name="document_entity_type", create_type=False)
    am = postgresql.ENUM(*APPROVAL_MODES, name="approval_mode", create_type=False)
    ast = postgresql.ENUM(*APPROVAL_STATUSES, name="approval_status", create_type=False)
    adv = postgresql.ENUM(*APPROVAL_DECISION_VALUES, name="approval_decision_value", create_type=False)

    # ── document_attachments ───────────────────────────────────────
    op.create_table(
        "document_attachments",
        sa.Column("id", UUID(as_uuid=True), primary_key=True),
        sa.Column("company_id", UUID(as_uuid=True), sa.ForeignKey("companies.id"), nullable=False),
        sa.Column("entity_type", det, nullable=False),
        sa.Column("entity_id", UUID(as_uuid=True), nullable=False, index=True),
        sa.Column("uploaded_by_user_id", UUID(as_uuid=True), sa.ForeignKey("users.id"), nullable=False),
        sa.Column("original_filename", sa.String(500), nullable=False),
        sa.Column("stored_filename", sa.String(500), nullable=False),
        sa.Column("content_type", sa.String(200), nullable=False),
        sa.Column("file_size_bytes", sa.Integer, nullable=False),
        sa.Column("description", sa.String(500), nullable=True),
        sa.Column("uploaded_at", sa.DateTime(timezone=True), server_default=sa.func.now()),
        sa.Column("is_deleted", sa.Boolean, default=False, nullable=False),
    )

    # ── document_signatures ────────────────────────────────────────
    op.create_table(
        "document_signatures",
        sa.Column("id", UUID(as_uuid=True), primary_key=True),
        sa.Column("company_id", UUID(as_uuid=True), sa.ForeignKey("companies.id"), nullable=False),
        sa.Column("entity_type", det, nullable=False),
        sa.Column("entity_id", UUID(as_uuid=True), nullable=False, index=True),
        sa.Column("signer_user_id", UUID(as_uuid=True), sa.ForeignKey("users.id"), nullable=False),
        sa.Column("signer_name", sa.String(255), nullable=False),
        sa.Column("signature_data_uri", sa.Text, nullable=False),
        sa.Column("role_label", sa.String(100), nullable=True),
        sa.Column("signed_at", sa.DateTime(timezone=True), server_default=sa.func.now()),
        sa.Column("is_deleted", sa.Boolean, default=False, nullable=False),
    )

    # ── approval_authorities ───────────────────────────────────────
    op.create_table(
        "approval_authorities",
        sa.Column("id", UUID(as_uuid=True), primary_key=True),
        sa.Column("company_id", UUID(as_uuid=True), sa.ForeignKey("companies.id"), nullable=False),
        sa.Column("name", sa.String(200), nullable=False),
        sa.Column("description", sa.String(500), nullable=True),
        sa.Column("mode", am, nullable=False, server_default="any_one"),
        sa.Column("bank_account_id", UUID(as_uuid=True), sa.ForeignKey("bank_accounts.id"), nullable=True),
        sa.Column("is_active", sa.Boolean, default=True, nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now()),
        sa.UniqueConstraint("company_id", "name", name="uq_approval_authority_name"),
    )

    # ── approval_authority_members ─────────────────────────────────
    op.create_table(
        "approval_authority_members",
        sa.Column("id", UUID(as_uuid=True), primary_key=True),
        sa.Column("authority_id", UUID(as_uuid=True), sa.ForeignKey("approval_authorities.id"), nullable=False),
        sa.Column("user_id", UUID(as_uuid=True), sa.ForeignKey("users.id"), nullable=False),
        sa.Column("added_at", sa.DateTime(timezone=True), server_default=sa.func.now()),
        sa.UniqueConstraint("authority_id", "user_id", name="uq_approval_member"),
    )

    # ── approval_rules ─────────────────────────────────────────────
    op.create_table(
        "approval_rules",
        sa.Column("id", UUID(as_uuid=True), primary_key=True),
        sa.Column("authority_id", UUID(as_uuid=True), sa.ForeignKey("approval_authorities.id"), nullable=False),
        sa.Column("entity_type", det, nullable=False),
        sa.Column("threshold_amount", sa.Numeric(15, 2), nullable=True),
        sa.Column("priority", sa.Integer, nullable=False, server_default="0"),
        sa.Column("is_active", sa.Boolean, default=True, nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now()),
    )

    # ── approval_requests ──────────────────────────────────────────
    op.create_table(
        "approval_requests",
        sa.Column("id", UUID(as_uuid=True), primary_key=True),
        sa.Column("company_id", UUID(as_uuid=True), sa.ForeignKey("companies.id"), nullable=False),
        sa.Column("entity_type", det, nullable=False),
        sa.Column("entity_id", UUID(as_uuid=True), nullable=False, index=True),
        sa.Column("rule_id", UUID(as_uuid=True), sa.ForeignKey("approval_rules.id"), nullable=False),
        sa.Column("authority_id", UUID(as_uuid=True), sa.ForeignKey("approval_authorities.id"), nullable=False),
        sa.Column("status", ast, nullable=False, server_default="pending"),
        sa.Column("requested_by_user_id", UUID(as_uuid=True), sa.ForeignKey("users.id"), nullable=False),
        sa.Column("requested_at", sa.DateTime(timezone=True), server_default=sa.func.now()),
        sa.Column("resolved_at", sa.DateTime(timezone=True), nullable=True),
    )

    # ── approval_decisions ─────────────────────────────────────────
    op.create_table(
        "approval_decisions",
        sa.Column("id", UUID(as_uuid=True), primary_key=True),
        sa.Column("request_id", UUID(as_uuid=True), sa.ForeignKey("approval_requests.id"), nullable=False),
        sa.Column("user_id", UUID(as_uuid=True), sa.ForeignKey("users.id"), nullable=False),
        sa.Column("decision", adv, nullable=False),
        sa.Column("comment", sa.Text, nullable=True),
        sa.Column("decided_at", sa.DateTime(timezone=True), server_default=sa.func.now()),
        sa.UniqueConstraint("request_id", "user_id", name="uq_approval_decision"),
    )


def downgrade() -> None:
    op.drop_table("approval_decisions")
    op.drop_table("approval_requests")
    op.drop_table("approval_rules")
    op.drop_table("approval_authority_members")
    op.drop_table("approval_authorities")
    op.drop_table("document_signatures")
    op.drop_table("document_attachments")

    sa.Enum(name="approval_decision_value").drop(op.get_bind(), checkfirst=True)
    sa.Enum(name="approval_status").drop(op.get_bind(), checkfirst=True)
    sa.Enum(name="approval_mode").drop(op.get_bind(), checkfirst=True)
    sa.Enum(name="document_entity_type").drop(op.get_bind(), checkfirst=True)
