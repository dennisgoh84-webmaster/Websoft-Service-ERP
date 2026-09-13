"""stock_item_extended_fields_and_setup_masters

Revision ID: dfd036f77dd0
Revises: ef5b4b028222
Create Date: 2026-09-13 11:36:00.175952

"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa

# revision identifiers, used by Alembic.
revision: str = 'dfd036f77dd0'
down_revision: Union[str, Sequence[str], None] = 'ef5b4b028222'
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    """Add stock setup master tables + extend stock_items with new fields."""

    # ── Stock Categories ────────────────────────────────────────────
    op.create_table(
        'stock_categories',
        sa.Column('id', sa.UUID(), nullable=False, server_default=sa.text('gen_random_uuid()')),
        sa.Column('company_id', sa.UUID(), nullable=False),
        sa.Column('code', sa.String(30), nullable=False),
        sa.Column('name', sa.String(200), nullable=False),
        sa.Column('is_active', sa.Boolean(), nullable=False, server_default=sa.text('true')),
        sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()')),
        sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()')),
        sa.ForeignKeyConstraint(['company_id'], ['companies.id']),
        sa.PrimaryKeyConstraint('id'),
    )

    # ── Stock Groups ────────────────────────────────────────────────
    op.create_table(
        'stock_groups',
        sa.Column('id', sa.UUID(), nullable=False, server_default=sa.text('gen_random_uuid()')),
        sa.Column('company_id', sa.UUID(), nullable=False),
        sa.Column('code', sa.String(30), nullable=False),
        sa.Column('name', sa.String(200), nullable=False),
        sa.Column('is_active', sa.Boolean(), nullable=False, server_default=sa.text('true')),
        sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()')),
        sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()')),
        sa.ForeignKeyConstraint(['company_id'], ['companies.id']),
        sa.PrimaryKeyConstraint('id'),
    )

    # ── Stock Brands ────────────────────────────────────────────────
    op.create_table(
        'stock_brands',
        sa.Column('id', sa.UUID(), nullable=False, server_default=sa.text('gen_random_uuid()')),
        sa.Column('company_id', sa.UUID(), nullable=False),
        sa.Column('name', sa.String(200), nullable=False),
        sa.Column('is_active', sa.Boolean(), nullable=False, server_default=sa.text('true')),
        sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()')),
        sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()')),
        sa.ForeignKeyConstraint(['company_id'], ['companies.id']),
        sa.PrimaryKeyConstraint('id'),
    )

    # ── Stock Models (child of Brand) ──────────────────────────────
    op.create_table(
        'stock_models',
        sa.Column('id', sa.UUID(), nullable=False, server_default=sa.text('gen_random_uuid()')),
        sa.Column('brand_id', sa.UUID(), nullable=False),
        sa.Column('name', sa.String(200), nullable=False),
        sa.Column('is_active', sa.Boolean(), nullable=False, server_default=sa.text('true')),
        sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()')),
        sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()')),
        sa.ForeignKeyConstraint(['brand_id'], ['stock_brands.id']),
        sa.PrimaryKeyConstraint('id'),
    )

    # ── Stock Usages ────────────────────────────────────────────────
    op.create_table(
        'stock_usages',
        sa.Column('id', sa.UUID(), nullable=False, server_default=sa.text('gen_random_uuid()')),
        sa.Column('company_id', sa.UUID(), nullable=False),
        sa.Column('code', sa.String(30), nullable=False),
        sa.Column('name', sa.String(200), nullable=False),
        sa.Column('is_active', sa.Boolean(), nullable=False, server_default=sa.text('true')),
        sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()')),
        sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()')),
        sa.ForeignKeyConstraint(['company_id'], ['companies.id']),
        sa.PrimaryKeyConstraint('id'),
    )

    # ── Stock Item Attachments ──────────────────────────────────────
    op.create_table(
        'stock_item_attachments',
        sa.Column('id', sa.UUID(), nullable=False, server_default=sa.text('gen_random_uuid()')),
        sa.Column('stock_item_id', sa.UUID(), nullable=False),
        sa.Column('filename', sa.String(500), nullable=False),
        sa.Column('stored_filename', sa.String(500), nullable=False),
        sa.Column('content_type', sa.String(100), nullable=True),
        sa.Column('file_size', sa.Integer(), nullable=True),
        sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()')),
        sa.ForeignKeyConstraint(['stock_item_id'], ['stock_items.id']),
        sa.PrimaryKeyConstraint('id'),
    )

    # ── Add new columns to stock_items ──────────────────────────────
    op.add_column('stock_items', sa.Column('category_id', sa.UUID(), nullable=True))
    op.add_column('stock_items', sa.Column('group_id', sa.UUID(), nullable=True))
    op.add_column('stock_items', sa.Column('brand_id', sa.UUID(), nullable=True))
    op.add_column('stock_items', sa.Column('model_id', sa.UUID(), nullable=True))
    op.add_column('stock_items', sa.Column('usage_id', sa.UUID(), nullable=True))
    op.add_column('stock_items', sa.Column('barcode', sa.String(100), nullable=True))
    op.add_column('stock_items', sa.Column('part_number', sa.String(100), nullable=True))
    op.add_column('stock_items', sa.Column('invoice_description', sa.Text(), nullable=True))
    op.add_column('stock_items', sa.Column('memo', sa.Text(), nullable=True))
    op.add_column('stock_items', sa.Column('notes', sa.Text(), nullable=True))
    op.add_column('stock_items', sa.Column('dimensions', sa.String(255), nullable=True))

    op.create_foreign_key('fk_stock_items_category_id', 'stock_items', 'stock_categories', ['category_id'], ['id'])
    op.create_foreign_key('fk_stock_items_group_id', 'stock_items', 'stock_groups', ['group_id'], ['id'])
    op.create_foreign_key('fk_stock_items_brand_id', 'stock_items', 'stock_brands', ['brand_id'], ['id'])
    op.create_foreign_key('fk_stock_items_model_id', 'stock_items', 'stock_models', ['model_id'], ['id'])
    op.create_foreign_key('fk_stock_items_usage_id', 'stock_items', 'stock_usages', ['usage_id'], ['id'])


def downgrade() -> None:
    """Remove extended stock fields and setup master tables."""
    op.drop_constraint('fk_stock_items_usage_id', 'stock_items', type_='foreignkey')
    op.drop_constraint('fk_stock_items_model_id', 'stock_items', type_='foreignkey')
    op.drop_constraint('fk_stock_items_brand_id', 'stock_items', type_='foreignkey')
    op.drop_constraint('fk_stock_items_group_id', 'stock_items', type_='foreignkey')
    op.drop_constraint('fk_stock_items_category_id', 'stock_items', type_='foreignkey')

    op.drop_column('stock_items', 'dimensions')
    op.drop_column('stock_items', 'notes')
    op.drop_column('stock_items', 'memo')
    op.drop_column('stock_items', 'invoice_description')
    op.drop_column('stock_items', 'part_number')
    op.drop_column('stock_items', 'barcode')
    op.drop_column('stock_items', 'usage_id')
    op.drop_column('stock_items', 'model_id')
    op.drop_column('stock_items', 'brand_id')
    op.drop_column('stock_items', 'group_id')
    op.drop_column('stock_items', 'category_id')

    op.drop_table('stock_item_attachments')
    op.drop_table('stock_usages')
    op.drop_table('stock_models')
    op.drop_table('stock_brands')
    op.drop_table('stock_groups')
    op.drop_table('stock_categories')
