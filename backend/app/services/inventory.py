"""
Stock / Inventory business logic.

INV-002: weighted average cost. When stock is received (GRN), the
average cost at that warehouse is recalculated:
  new_avg = (existing_qty * existing_avg + received_qty * received_cost)
            / (existing_qty + received_qty)

When stock leaves (transfer out, return, adjustment down) the avg cost
stays the same at the source location -- units leave at their current
weighted average.

INV-001: stock adjustments require manager approval. The adjustment
document must be approved before stock levels change.
"""
import uuid
from decimal import Decimal, ROUND_HALF_UP

from sqlalchemy.orm import Session

from app.models.inventory import (
    AdjustmentStatus,
    DocumentStatus,
    GoodsReceiveNote,
    GoodsReceiveNoteLine,
    GoodsReturnNote,
    GoodsReturnNoteLine,
    GoodsTransferNote,
    GoodsTransferNoteLine,
    MovementType,
    StockAdjustment,
    StockAdjustmentLine,
    StockItem,
    StockLevel,
    StockMovement,
    Warehouse,
)


def get_or_create_stock_level(
    db: Session, company_id: uuid.UUID, stock_item_id: uuid.UUID, warehouse_id: uuid.UUID
) -> StockLevel:
    """Get existing stock level or create a zero row."""
    sl = (
        db.query(StockLevel)
        .filter(
            StockLevel.company_id == company_id,
            StockLevel.stock_item_id == stock_item_id,
            StockLevel.warehouse_id == warehouse_id,
        )
        .first()
    )
    if not sl:
        sl = StockLevel(
            company_id=company_id,
            stock_item_id=stock_item_id,
            warehouse_id=warehouse_id,
            quantity=0,
            avg_cost=Decimal("0"),
        )
        db.add(sl)
        db.flush()
    return sl


def receive_stock(
    db: Session,
    company_id: uuid.UUID,
    stock_item_id: uuid.UUID,
    warehouse_id: uuid.UUID,
    qty: int,
    unit_cost: Decimal,
    reference_type: str,
    reference_id: uuid.UUID,
    user_id: uuid.UUID | None = None,
    notes: str | None = None,
) -> StockMovement:
    """Add stock via GRN. Recalculates weighted average cost (INV-002)."""
    sl = get_or_create_stock_level(db, company_id, stock_item_id, warehouse_id)

    # Weighted average cost
    existing_value = Decimal(sl.quantity) * sl.avg_cost
    incoming_value = Decimal(qty) * unit_cost
    new_qty = sl.quantity + qty
    if new_qty > 0:
        sl.avg_cost = ((existing_value + incoming_value) / Decimal(new_qty)).quantize(
            Decimal("0.0001"), rounding=ROUND_HALF_UP
        )
    sl.quantity = new_qty

    total_cost = (Decimal(qty) * unit_cost).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)
    mv = StockMovement(
        company_id=company_id,
        stock_item_id=stock_item_id,
        warehouse_id=warehouse_id,
        movement_type=MovementType.receive,
        quantity=qty,
        unit_cost=unit_cost,
        total_cost=total_cost,
        reference_type=reference_type,
        reference_id=reference_id,
        notes=notes,
        created_by=user_id,
    )
    db.add(mv)
    return mv


def deduct_stock(
    db: Session,
    company_id: uuid.UUID,
    stock_item_id: uuid.UUID,
    warehouse_id: uuid.UUID,
    qty: int,
    movement_type: MovementType,
    reference_type: str,
    reference_id: uuid.UUID,
    user_id: uuid.UUID | None = None,
    notes: str | None = None,
) -> StockMovement:
    """Remove stock (transfer out, return, adjustment down). Uses current
    weighted average cost as the unit cost for the movement."""
    sl = get_or_create_stock_level(db, company_id, stock_item_id, warehouse_id)
    if sl.quantity < qty:
        raise ValueError(f"Insufficient stock: have {sl.quantity}, need {qty}")

    unit_cost = sl.avg_cost
    total_cost = (Decimal(qty) * unit_cost).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)
    sl.quantity -= qty
    # avg_cost stays the same when deducting

    mv = StockMovement(
        company_id=company_id,
        stock_item_id=stock_item_id,
        warehouse_id=warehouse_id,
        movement_type=movement_type,
        quantity=-qty,
        unit_cost=unit_cost,
        total_cost=total_cost,
        reference_type=reference_type,
        reference_id=reference_id,
        notes=notes,
        created_by=user_id,
    )
    db.add(mv)
    return mv


def adjust_stock_increase(
    db: Session,
    company_id: uuid.UUID,
    stock_item_id: uuid.UUID,
    warehouse_id: uuid.UUID,
    qty: int,
    reference_id: uuid.UUID,
    user_id: uuid.UUID | None = None,
    notes: str | None = None,
) -> StockMovement:
    """Positive adjustment -- add qty at current avg cost (no new cost info)."""
    sl = get_or_create_stock_level(db, company_id, stock_item_id, warehouse_id)
    sl.quantity += qty

    mv = StockMovement(
        company_id=company_id,
        stock_item_id=stock_item_id,
        warehouse_id=warehouse_id,
        movement_type=MovementType.adjustment,
        quantity=qty,
        unit_cost=sl.avg_cost,
        total_cost=(Decimal(qty) * sl.avg_cost).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP),
        reference_type="adj",
        reference_id=reference_id,
        notes=notes,
        created_by=user_id,
    )
    db.add(mv)
    return mv


# ── GRN confirm ─────────────────────────────────────────────────────

def confirm_grn(db: Session, grn: GoodsReceiveNote, user_id: uuid.UUID | None = None):
    """Confirm a GRN: update stock levels for every line."""
    if grn.status != DocumentStatus.draft:
        raise ValueError("GRN is not in draft status")
    for line in grn.lines:
        receive_stock(
            db, grn.company_id, line.stock_item_id, grn.warehouse_id,
            line.quantity, line.unit_cost,
            reference_type="grn", reference_id=grn.id,
            user_id=user_id,
        )
    grn.status = DocumentStatus.confirmed


# ── GTN confirm ─────────────────────────────────────────────────────

def confirm_gtn(db: Session, gtn: GoodsTransferNote, user_id: uuid.UUID | None = None):
    """Confirm a GTN: deduct from source warehouse, add to destination."""
    if gtn.status != DocumentStatus.draft:
        raise ValueError("GTN is not in draft status")
    for line in gtn.lines:
        # Get the avg cost at source before deducting
        src_sl = get_or_create_stock_level(db, gtn.company_id, line.stock_item_id, gtn.from_warehouse_id)
        transfer_cost = src_sl.avg_cost

        deduct_stock(
            db, gtn.company_id, line.stock_item_id, gtn.from_warehouse_id,
            line.quantity, MovementType.transfer_out,
            reference_type="gtn", reference_id=gtn.id,
            user_id=user_id,
        )
        receive_stock(
            db, gtn.company_id, line.stock_item_id, gtn.to_warehouse_id,
            line.quantity, transfer_cost,
            reference_type="gtn", reference_id=gtn.id,
            user_id=user_id,
        )
    gtn.status = DocumentStatus.confirmed


# ── GRTN confirm ────────────────────────────────────────────────────

def confirm_grtn(db: Session, grtn: GoodsReturnNote, user_id: uuid.UUID | None = None):
    """Confirm a Goods Return Note: deduct stock from warehouse."""
    if grtn.status != DocumentStatus.draft:
        raise ValueError("GRTN is not in draft status")
    for line in grtn.lines:
        deduct_stock(
            db, grtn.company_id, line.stock_item_id, grtn.warehouse_id,
            line.quantity, MovementType.return_out,
            reference_type="grtn", reference_id=grtn.id,
            user_id=user_id,
        )
    grtn.status = DocumentStatus.confirmed


# ── Stock Adjustment approve ────────────────────────────────────────

def approve_adjustment(
    db: Session, adj: StockAdjustment, approver_id: uuid.UUID
):
    """INV-001: approve a stock adjustment and apply the stock changes."""
    if adj.status != AdjustmentStatus.pending_approval:
        raise ValueError("Adjustment must be pending approval")

    from datetime import datetime, timezone
    adj.status = AdjustmentStatus.approved
    adj.approved_by = approver_id
    adj.approved_at = datetime.now(timezone.utc)

    for line in adj.lines:
        if line.quantity_change > 0:
            adjust_stock_increase(
                db, adj.company_id, line.stock_item_id, adj.warehouse_id,
                line.quantity_change, adj.id, approver_id, line.notes,
            )
        elif line.quantity_change < 0:
            deduct_stock(
                db, adj.company_id, line.stock_item_id, adj.warehouse_id,
                abs(line.quantity_change), MovementType.adjustment,
                reference_type="adj", reference_id=adj.id,
                user_id=approver_id, notes=line.notes,
            )
