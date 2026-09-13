"""add stock inventory tables

Revision ID: ef5b4b028222
Revises: b2c300000001
Create Date: 2026-09-13 10:50:23.468222

"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

# revision identifiers, used by Alembic.
revision: str = 'ef5b4b028222'
down_revision: Union[str, Sequence[str], None] = 'b2c300000001'
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    """Create stock/inventory tables."""

    # Enums
    movement_type = postgresql.ENUM(
        'receive', 'transfer_out', 'transfer_in', 'return_out', 'adjustment', 'issue',
        name='movement_type', create_type=False,
    )
    adjustment_status = postgresql.ENUM(
        'draft', 'pending_approval', 'approved', 'rejected',
        name='adjustment_status', create_type=False,
    )
    grn_status = postgresql.ENUM('draft', 'confirmed', 'cancelled', name='grn_status', create_type=False)
    gtn_status = postgresql.ENUM('draft', 'confirmed', 'cancelled', name='gtn_status', create_type=False)
    grtn_status = postgresql.ENUM('draft', 'confirmed', 'cancelled', name='grtn_status', create_type=False)

    op.execute("CREATE TYPE movement_type AS ENUM ('receive','transfer_out','transfer_in','return_out','adjustment','issue')")
    op.execute("CREATE TYPE adjustment_status AS ENUM ('draft','pending_approval','approved','rejected')")
    op.execute("CREATE TYPE grn_status AS ENUM ('draft','confirmed','cancelled')")
    op.execute("CREATE TYPE gtn_status AS ENUM ('draft','confirmed','cancelled')")
    op.execute("CREATE TYPE grtn_status AS ENUM ('draft','confirmed','cancelled')")

    # Warehouses
    op.create_table(
        'warehouses',
        sa.Column('id', postgresql.UUID(as_uuid=True), primary_key=True, server_default=sa.text('gen_random_uuid()')),
        sa.Column('company_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('companies.id'), nullable=False),
        sa.Column('code', sa.String(20), nullable=False),
        sa.Column('name', sa.String(200), nullable=False),
        sa.Column('address', sa.Text, nullable=True),
        sa.Column('is_active', sa.Boolean, nullable=False, server_default=sa.text('true')),
        sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()')),
        sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()')),
    )

    # Stock Items
    op.create_table(
        'stock_items',
        sa.Column('id', postgresql.UUID(as_uuid=True), primary_key=True, server_default=sa.text('gen_random_uuid()')),
        sa.Column('company_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('companies.id'), nullable=False),
        sa.Column('code', sa.String(50), nullable=False),
        sa.Column('name', sa.String(255), nullable=False),
        sa.Column('description', sa.Text, nullable=True),
        sa.Column('category', sa.String(100), nullable=True),
        sa.Column('unit_of_measure', sa.String(30), nullable=False, server_default='PCS'),
        sa.Column('product_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('products.id'), nullable=True),
        sa.Column('reorder_level', sa.Integer, nullable=False, server_default='0'),
        sa.Column('is_active', sa.Boolean, nullable=False, server_default=sa.text('true')),
        sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()')),
        sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()')),
    )

    # Stock Levels
    op.create_table(
        'stock_levels',
        sa.Column('id', postgresql.UUID(as_uuid=True), primary_key=True, server_default=sa.text('gen_random_uuid()')),
        sa.Column('company_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('companies.id'), nullable=False),
        sa.Column('stock_item_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('stock_items.id'), nullable=False),
        sa.Column('warehouse_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('warehouses.id'), nullable=False),
        sa.Column('quantity', sa.Integer, nullable=False, server_default='0'),
        sa.Column('avg_cost', sa.Numeric(14, 4), nullable=False, server_default='0'),
        sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()')),
    )

    # Stock Movements
    op.create_table(
        'stock_movements',
        sa.Column('id', postgresql.UUID(as_uuid=True), primary_key=True, server_default=sa.text('gen_random_uuid()')),
        sa.Column('company_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('companies.id'), nullable=False),
        sa.Column('stock_item_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('stock_items.id'), nullable=False),
        sa.Column('warehouse_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('warehouses.id'), nullable=False),
        sa.Column('movement_type', movement_type, nullable=False),
        sa.Column('quantity', sa.Integer, nullable=False),
        sa.Column('unit_cost', sa.Numeric(14, 4), nullable=False, server_default='0'),
        sa.Column('total_cost', sa.Numeric(14, 2), nullable=False, server_default='0'),
        sa.Column('reference_type', sa.String(30), nullable=True),
        sa.Column('reference_id', postgresql.UUID(as_uuid=True), nullable=True),
        sa.Column('notes', sa.Text, nullable=True),
        sa.Column('created_by', postgresql.UUID(as_uuid=True), sa.ForeignKey('users.id'), nullable=True),
        sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()')),
    )

    # GRN
    op.create_table(
        'goods_receive_notes',
        sa.Column('id', postgresql.UUID(as_uuid=True), primary_key=True, server_default=sa.text('gen_random_uuid()')),
        sa.Column('company_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('companies.id'), nullable=False),
        sa.Column('grn_number', sa.String(30), nullable=False),
        sa.Column('warehouse_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('warehouses.id'), nullable=False),
        sa.Column('supplier_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('company_individuals.id'), nullable=True),
        sa.Column('purchase_order_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('purchase_orders.id'), nullable=True),
        sa.Column('receive_date', sa.DateTime(timezone=True), nullable=False, server_default=sa.text('now()')),
        sa.Column('status', grn_status, nullable=False, server_default='draft'),
        sa.Column('notes', sa.Text, nullable=True),
        sa.Column('created_by', postgresql.UUID(as_uuid=True), sa.ForeignKey('users.id'), nullable=True),
        sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()')),
        sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()')),
    )
    op.create_table(
        'goods_receive_note_lines',
        sa.Column('id', postgresql.UUID(as_uuid=True), primary_key=True, server_default=sa.text('gen_random_uuid()')),
        sa.Column('grn_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('goods_receive_notes.id'), nullable=False),
        sa.Column('stock_item_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('stock_items.id'), nullable=False),
        sa.Column('quantity', sa.Integer, nullable=False),
        sa.Column('unit_cost', sa.Numeric(14, 4), nullable=False, server_default='0'),
        sa.Column('total_cost', sa.Numeric(14, 2), nullable=False, server_default='0'),
        sa.Column('notes', sa.Text, nullable=True),
    )

    # GTN
    op.create_table(
        'goods_transfer_notes',
        sa.Column('id', postgresql.UUID(as_uuid=True), primary_key=True, server_default=sa.text('gen_random_uuid()')),
        sa.Column('company_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('companies.id'), nullable=False),
        sa.Column('gtn_number', sa.String(30), nullable=False),
        sa.Column('from_warehouse_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('warehouses.id'), nullable=False),
        sa.Column('to_warehouse_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('warehouses.id'), nullable=False),
        sa.Column('transfer_date', sa.DateTime(timezone=True), nullable=False, server_default=sa.text('now()')),
        sa.Column('status', gtn_status, nullable=False, server_default='draft'),
        sa.Column('notes', sa.Text, nullable=True),
        sa.Column('created_by', postgresql.UUID(as_uuid=True), sa.ForeignKey('users.id'), nullable=True),
        sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()')),
        sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()')),
    )
    op.create_table(
        'goods_transfer_note_lines',
        sa.Column('id', postgresql.UUID(as_uuid=True), primary_key=True, server_default=sa.text('gen_random_uuid()')),
        sa.Column('gtn_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('goods_transfer_notes.id'), nullable=False),
        sa.Column('stock_item_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('stock_items.id'), nullable=False),
        sa.Column('quantity', sa.Integer, nullable=False),
        sa.Column('notes', sa.Text, nullable=True),
    )

    # GRTN
    op.create_table(
        'goods_return_notes',
        sa.Column('id', postgresql.UUID(as_uuid=True), primary_key=True, server_default=sa.text('gen_random_uuid()')),
        sa.Column('company_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('companies.id'), nullable=False),
        sa.Column('grtn_number', sa.String(30), nullable=False),
        sa.Column('warehouse_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('warehouses.id'), nullable=False),
        sa.Column('supplier_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('company_individuals.id'), nullable=True),
        sa.Column('return_date', sa.DateTime(timezone=True), nullable=False, server_default=sa.text('now()')),
        sa.Column('reason', sa.Text, nullable=True),
        sa.Column('status', grtn_status, nullable=False, server_default='draft'),
        sa.Column('notes', sa.Text, nullable=True),
        sa.Column('created_by', postgresql.UUID(as_uuid=True), sa.ForeignKey('users.id'), nullable=True),
        sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()')),
        sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()')),
    )
    op.create_table(
        'goods_return_note_lines',
        sa.Column('id', postgresql.UUID(as_uuid=True), primary_key=True, server_default=sa.text('gen_random_uuid()')),
        sa.Column('grtn_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('goods_return_notes.id'), nullable=False),
        sa.Column('stock_item_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('stock_items.id'), nullable=False),
        sa.Column('quantity', sa.Integer, nullable=False),
        sa.Column('unit_cost', sa.Numeric(14, 4), nullable=False, server_default='0'),
        sa.Column('total_cost', sa.Numeric(14, 2), nullable=False, server_default='0'),
        sa.Column('notes', sa.Text, nullable=True),
    )

    # Stock Adjustment
    op.create_table(
        'stock_adjustments',
        sa.Column('id', postgresql.UUID(as_uuid=True), primary_key=True, server_default=sa.text('gen_random_uuid()')),
        sa.Column('company_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('companies.id'), nullable=False),
        sa.Column('adj_number', sa.String(30), nullable=False),
        sa.Column('warehouse_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('warehouses.id'), nullable=False),
        sa.Column('adjustment_date', sa.DateTime(timezone=True), nullable=False, server_default=sa.text('now()')),
        sa.Column('reason', sa.Text, nullable=True),
        sa.Column('status', adjustment_status, nullable=False, server_default='draft'),
        sa.Column('approved_by', postgresql.UUID(as_uuid=True), sa.ForeignKey('users.id'), nullable=True),
        sa.Column('approved_at', sa.DateTime(timezone=True), nullable=True),
        sa.Column('created_by', postgresql.UUID(as_uuid=True), sa.ForeignKey('users.id'), nullable=True),
        sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()')),
        sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()')),
    )
    op.create_table(
        'stock_adjustment_lines',
        sa.Column('id', postgresql.UUID(as_uuid=True), primary_key=True, server_default=sa.text('gen_random_uuid()')),
        sa.Column('adjustment_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('stock_adjustments.id'), nullable=False),
        sa.Column('stock_item_id', postgresql.UUID(as_uuid=True), sa.ForeignKey('stock_items.id'), nullable=False),
        sa.Column('quantity_change', sa.Integer, nullable=False),
        sa.Column('notes', sa.Text, nullable=True),
    )


def downgrade() -> None:
    """Drop stock/inventory tables."""
    op.drop_table('stock_adjustment_lines')
    op.drop_table('stock_adjustments')
    op.drop_table('goods_return_note_lines')
    op.drop_table('goods_return_notes')
    op.drop_table('goods_transfer_note_lines')
    op.drop_table('goods_transfer_notes')
    op.drop_table('goods_receive_note_lines')
    op.drop_table('goods_receive_notes')
    op.drop_table('stock_movements')
    op.drop_table('stock_levels')
    op.drop_table('stock_items')
    op.drop_table('warehouses')
    op.execute("DROP TYPE IF EXISTS grtn_status")
    op.execute("DROP TYPE IF EXISTS gtn_status")
    op.execute("DROP TYPE IF EXISTS grn_status")
    op.execute("DROP TYPE IF EXISTS adjustment_status")
    op.execute("DROP TYPE IF EXISTS movement_type")
