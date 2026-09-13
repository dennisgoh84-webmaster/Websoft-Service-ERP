"""
Stock / Inventory module models.

Confirmed business rules:
- INV-001: Stock adjustments require manager approval before taking effect.
- INV-002: Inventory is valued using weighted average cost.

Six module keys govern access:
  stock_master           -- Item master, warehouses, stock levels
  goods_receive_note     -- GRN (receipt of goods from supplier)
  goods_transfer_note    -- GTN (inter-warehouse transfers)
  goods_return_note      -- GRTN (return goods to supplier)
  stock_adjustment       -- Adjust stock (damage, loss, count variance)
  stock_operation_reports -- Stock reports (stock card, valuation, movement)
"""
import enum
import uuid
from datetime import datetime
from decimal import Decimal

from sqlalchemy import (
    Boolean,
    DateTime,
    Enum,
    ForeignKey,
    Integer,
    Numeric,
    String,
    Text,
    func,
)
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base


# ── Enums ────────────────────────────────────────────────────────────

class MovementType(str, enum.Enum):
    """Classifies every stock movement."""
    receive = "receive"       # GRN
    transfer_out = "transfer_out"
    transfer_in = "transfer_in"
    return_out = "return_out"  # GRTN (return to supplier)
    adjustment = "adjustment"
    issue = "issue"           # future: issue to job order / sale


class AdjustmentStatus(str, enum.Enum):
    """INV-001: adjustments require manager approval."""
    draft = "draft"
    pending_approval = "pending_approval"
    approved = "approved"
    rejected = "rejected"


class DocumentStatus(str, enum.Enum):
    """General document status for GRN / GTN / GRTN."""
    draft = "draft"
    confirmed = "confirmed"
    cancelled = "cancelled"


# ── Stock Setup Master Files ────────────────────────────────────────

class StockCategory(Base):
    """Setup master: stock item categories (e.g. Hardware, Software, Consumable)."""
    __tablename__ = "stock_categories"

    id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    code: Mapped[str] = mapped_column(String(30), nullable=False)
    name: Mapped[str] = mapped_column(String(200), nullable=False)
    is_active: Mapped[bool] = mapped_column(Boolean, nullable=False, default=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now(), onupdate=func.now())


class StockGroup(Base):
    """Setup master: stock item groups (e.g. Networking, Printers, Cables)."""
    __tablename__ = "stock_groups"

    id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    code: Mapped[str] = mapped_column(String(30), nullable=False)
    name: Mapped[str] = mapped_column(String(200), nullable=False)
    is_active: Mapped[bool] = mapped_column(Boolean, nullable=False, default=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now(), onupdate=func.now())


class StockBrand(Base):
    """Setup master: brand (e.g. Cisco, HP, Dell). Models are children."""
    __tablename__ = "stock_brands"

    id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    name: Mapped[str] = mapped_column(String(200), nullable=False)
    is_active: Mapped[bool] = mapped_column(Boolean, nullable=False, default=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now(), onupdate=func.now())

    models = relationship("StockModel", back_populates="brand", cascade="all, delete-orphan")


class StockModel(Base):
    """Setup master: model under a brand (e.g. Cisco Catalyst 9300)."""
    __tablename__ = "stock_models"

    id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    brand_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("stock_brands.id"), nullable=False)
    name: Mapped[str] = mapped_column(String(200), nullable=False)
    is_active: Mapped[bool] = mapped_column(Boolean, nullable=False, default=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now(), onupdate=func.now())

    brand = relationship("StockBrand", back_populates="models")


class StockUsage(Base):
    """Setup master: intended usage (e.g. Resale, Internal, Project)."""
    __tablename__ = "stock_usages"

    id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    code: Mapped[str] = mapped_column(String(30), nullable=False)
    name: Mapped[str] = mapped_column(String(200), nullable=False)
    is_active: Mapped[bool] = mapped_column(Boolean, nullable=False, default=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now(), onupdate=func.now())


# ── Stock Item Attachment ──────────────────────────────────────────

class StockItemAttachment(Base):
    """Picture / document attachment on a stock item."""
    __tablename__ = "stock_item_attachments"

    id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    stock_item_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("stock_items.id"), nullable=False)
    filename: Mapped[str] = mapped_column(String(500), nullable=False)
    stored_filename: Mapped[str] = mapped_column(String(500), nullable=False)
    content_type: Mapped[str | None] = mapped_column(String(100), nullable=True)
    file_size: Mapped[int | None] = mapped_column(Integer, nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


# ── Warehouse / Location ────────────────────────────────────────────

class Warehouse(Base):
    """A physical location where stock is held."""
    __tablename__ = "warehouses"

    id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    code: Mapped[str] = mapped_column(String(20), nullable=False)
    name: Mapped[str] = mapped_column(String(200), nullable=False)
    address: Mapped[str | None] = mapped_column(Text, nullable=True)
    is_active: Mapped[bool] = mapped_column(Boolean, nullable=False, default=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now(), onupdate=func.now())


# ── Stock Item ──────────────────────────────────────────────────────

class StockItem(Base):
    """An inventory item tracked by quantity. Links to Product catalog
    when the item is also sold (product_id), but can exist independently
    for internal consumables."""
    __tablename__ = "stock_items"

    id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    code: Mapped[str] = mapped_column(String(50), nullable=False)
    name: Mapped[str] = mapped_column(String(255), nullable=False)
    description: Mapped[str | None] = mapped_column(Text, nullable=True)
    category: Mapped[str | None] = mapped_column(String(100), nullable=True)
    unit_of_measure: Mapped[str] = mapped_column(String(30), nullable=False, default="PCS")
    # Optional link to Product catalog (for items that are also sold)
    product_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("products.id"), nullable=True)
    # Reorder level — when total stock drops below this, flag for reorder
    reorder_level: Mapped[int] = mapped_column(Integer, nullable=False, default=0)

    # ── Extended fields (2026-09-13) ───────────────────────────────
    # FK links to setup master tables (nullable -- optional lookups)
    category_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("stock_categories.id"), nullable=True)
    group_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("stock_groups.id"), nullable=True)
    brand_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("stock_brands.id"), nullable=True)
    model_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("stock_models.id"), nullable=True)
    usage_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("stock_usages.id"), nullable=True)
    barcode: Mapped[str | None] = mapped_column(String(100), nullable=True)
    part_number: Mapped[str | None] = mapped_column(String(100), nullable=True)
    invoice_description: Mapped[str | None] = mapped_column(Text, nullable=True)
    memo: Mapped[str | None] = mapped_column(Text, nullable=True)
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)
    # Dimensions stored as free text (e.g. "300 x 200 x 100 mm, 2.5 kg")
    dimensions: Mapped[str | None] = mapped_column(String(255), nullable=True)

    is_active: Mapped[bool] = mapped_column(Boolean, nullable=False, default=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now(), onupdate=func.now())

    # Relationships for joined loading
    stock_category = relationship("StockCategory", foreign_keys=[category_id], lazy="joined")
    stock_group = relationship("StockGroup", foreign_keys=[group_id], lazy="joined")
    stock_brand = relationship("StockBrand", foreign_keys=[brand_id], lazy="joined")
    stock_model = relationship("StockModel", foreign_keys=[model_id], lazy="joined")
    stock_usage = relationship("StockUsage", foreign_keys=[usage_id], lazy="joined")
    attachments = relationship("StockItemAttachment", cascade="all, delete-orphan", order_by="StockItemAttachment.created_at")


# ── Stock Level (per item per warehouse) ────────────────────────────

class StockLevel(Base):
    """Current quantity and weighted average cost of a stock item at a
    warehouse. Updated by stock movements."""
    __tablename__ = "stock_levels"

    id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    stock_item_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("stock_items.id"), nullable=False)
    warehouse_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("warehouses.id"), nullable=False)
    quantity: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    # INV-002: weighted average cost per unit at this location
    avg_cost: Mapped[Decimal] = mapped_column(Numeric(14, 4), nullable=False, default=0)
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now(), onupdate=func.now())


# ── Stock Movement (journal of every qty change) ────────────────────

class StockMovement(Base):
    """An immutable record of a stock quantity change. Every GRN/GTN/GRTN/
    Adjustment line creates one (or two, for transfers) movement rows."""
    __tablename__ = "stock_movements"

    id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    stock_item_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("stock_items.id"), nullable=False)
    warehouse_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("warehouses.id"), nullable=False)
    movement_type: Mapped[MovementType] = mapped_column(Enum(MovementType, name="movement_type"), nullable=False)
    quantity: Mapped[int] = mapped_column(Integer, nullable=False)  # +ve = in, -ve = out
    unit_cost: Mapped[Decimal] = mapped_column(Numeric(14, 4), nullable=False, default=0)
    total_cost: Mapped[Decimal] = mapped_column(Numeric(14, 2), nullable=False, default=0)
    # Reference to the source document
    reference_type: Mapped[str | None] = mapped_column(String(30), nullable=True)  # grn / gtn / grtn / adj
    reference_id: Mapped[uuid.UUID | None] = mapped_column(UUID(as_uuid=True), nullable=True)
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)
    created_by: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


# ── Goods Receive Note (GRN) ────────────────────────────────────────

class GoodsReceiveNote(Base):
    """Receipt of goods from a supplier into a warehouse."""
    __tablename__ = "goods_receive_notes"

    id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    grn_number: Mapped[str] = mapped_column(String(30), nullable=False)
    warehouse_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("warehouses.id"), nullable=False)
    # Optional link to supplier (Company/Individual flagged is_supplier)
    supplier_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("company_individuals.id"), nullable=True)
    # Optional link to Purchase Order
    purchase_order_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("purchase_orders.id"), nullable=True)
    receive_date: Mapped[datetime] = mapped_column(DateTime(timezone=True), nullable=False, server_default=func.now())
    status: Mapped[DocumentStatus] = mapped_column(Enum(DocumentStatus, name="grn_status"), nullable=False, default=DocumentStatus.draft)
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)
    created_by: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now(), onupdate=func.now())

    lines = relationship("GoodsReceiveNoteLine", back_populates="grn", cascade="all, delete-orphan")


class GoodsReceiveNoteLine(Base):
    __tablename__ = "goods_receive_note_lines"

    id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    grn_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("goods_receive_notes.id"), nullable=False)
    stock_item_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("stock_items.id"), nullable=False)
    quantity: Mapped[int] = mapped_column(Integer, nullable=False)
    unit_cost: Mapped[Decimal] = mapped_column(Numeric(14, 4), nullable=False, default=0)
    total_cost: Mapped[Decimal] = mapped_column(Numeric(14, 2), nullable=False, default=0)
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)

    grn = relationship("GoodsReceiveNote", back_populates="lines")


# ── Goods Transfer Note (GTN) ──────────────────────────────────────

class GoodsTransferNote(Base):
    """Transfer of goods between warehouses."""
    __tablename__ = "goods_transfer_notes"

    id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    gtn_number: Mapped[str] = mapped_column(String(30), nullable=False)
    from_warehouse_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("warehouses.id"), nullable=False)
    to_warehouse_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("warehouses.id"), nullable=False)
    transfer_date: Mapped[datetime] = mapped_column(DateTime(timezone=True), nullable=False, server_default=func.now())
    status: Mapped[DocumentStatus] = mapped_column(Enum(DocumentStatus, name="gtn_status"), nullable=False, default=DocumentStatus.draft)
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)
    created_by: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now(), onupdate=func.now())

    lines = relationship("GoodsTransferNoteLine", back_populates="gtn", cascade="all, delete-orphan")


class GoodsTransferNoteLine(Base):
    __tablename__ = "goods_transfer_note_lines"

    id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    gtn_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("goods_transfer_notes.id"), nullable=False)
    stock_item_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("stock_items.id"), nullable=False)
    quantity: Mapped[int] = mapped_column(Integer, nullable=False)
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)

    gtn = relationship("GoodsTransferNote", back_populates="lines")


# ── Goods Return Note (GRTN) ───────────────────────────────────────

class GoodsReturnNote(Base):
    """Return of goods to a supplier."""
    __tablename__ = "goods_return_notes"

    id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    grtn_number: Mapped[str] = mapped_column(String(30), nullable=False)
    warehouse_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("warehouses.id"), nullable=False)
    supplier_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("company_individuals.id"), nullable=True)
    return_date: Mapped[datetime] = mapped_column(DateTime(timezone=True), nullable=False, server_default=func.now())
    reason: Mapped[str | None] = mapped_column(Text, nullable=True)
    status: Mapped[DocumentStatus] = mapped_column(Enum(DocumentStatus, name="grtn_status"), nullable=False, default=DocumentStatus.draft)
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)
    created_by: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now(), onupdate=func.now())

    lines = relationship("GoodsReturnNoteLine", back_populates="grtn", cascade="all, delete-orphan")


class GoodsReturnNoteLine(Base):
    __tablename__ = "goods_return_note_lines"

    id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    grtn_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("goods_return_notes.id"), nullable=False)
    stock_item_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("stock_items.id"), nullable=False)
    quantity: Mapped[int] = mapped_column(Integer, nullable=False)
    unit_cost: Mapped[Decimal] = mapped_column(Numeric(14, 4), nullable=False, default=0)
    total_cost: Mapped[Decimal] = mapped_column(Numeric(14, 2), nullable=False, default=0)
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)

    grtn = relationship("GoodsReturnNote", back_populates="lines")


# ── Stock Adjustment ────────────────────────────────────────────────

class StockAdjustment(Base):
    """INV-001: adjustments require manager approval. Status goes
    draft -> pending_approval -> approved (stock changes take effect)
    or rejected (no stock change)."""
    __tablename__ = "stock_adjustments"

    id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    adj_number: Mapped[str] = mapped_column(String(30), nullable=False)
    warehouse_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("warehouses.id"), nullable=False)
    adjustment_date: Mapped[datetime] = mapped_column(DateTime(timezone=True), nullable=False, server_default=func.now())
    reason: Mapped[str | None] = mapped_column(Text, nullable=True)
    status: Mapped[AdjustmentStatus] = mapped_column(
        Enum(AdjustmentStatus, name="adjustment_status"), nullable=False, default=AdjustmentStatus.draft
    )
    approved_by: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    approved_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_by: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now(), onupdate=func.now())

    lines = relationship("StockAdjustmentLine", back_populates="adjustment", cascade="all, delete-orphan")


class StockAdjustmentLine(Base):
    __tablename__ = "stock_adjustment_lines"

    id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    adjustment_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("stock_adjustments.id"), nullable=False)
    stock_item_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("stock_items.id"), nullable=False)
    # +ve = increase, -ve = decrease
    quantity_change: Mapped[int] = mapped_column(Integer, nullable=False)
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)

    adjustment = relationship("StockAdjustment", back_populates="lines")
