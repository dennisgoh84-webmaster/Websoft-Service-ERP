"""
Stock / Inventory API — covers all six stock module keys:
  stock_master, goods_receive_note, goods_transfer_note,
  goods_return_note, stock_adjustment, stock_operation_reports.

Each endpoint is gated by its own module key so Group Authority
can grant, e.g., warehouse staff GRN access without giving them
adjustment-approval access.
"""
import uuid
from datetime import datetime, timezone
from decimal import Decimal

from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy import func as sqlfunc
from sqlalchemy.orm import Session, selectinload

from app.core.database import get_db
from app.models.core import User
from app.models.groups import AccessLevel
from app.models.inventory import (
    AdjustmentStatus,
    DocumentStatus,
    GoodsReceiveNote,
    GoodsReceiveNoteLine,
    GoodsReturnNote,
    GoodsReturnNoteLine,
    GoodsTransferNote,
    GoodsTransferNoteLine,
    StockAdjustment,
    StockAdjustmentLine,
    StockItem,
    StockLevel,
    StockMovement,
    Warehouse,
)
from app.schemas.schemas import (
    AdjustmentCreate,
    AdjustmentOut,
    GRNCreate,
    GRNOut,
    GRTNCreate,
    GRTNOut,
    GTNCreate,
    GTNOut,
    StockItemCreate,
    StockItemOut,
    StockLevelOut,
    StockMovementOut,
    WarehouseCreate,
    WarehouseOut,
)
from app.services.authority import require_module_access
from app.services import inventory as inv_svc

router = APIRouter(prefix="/api/stock", tags=["stock"])


# ━━ Warehouses ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

@router.get("/warehouses", response_model=list[WarehouseOut])
def list_warehouses(
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access("stock_master", AccessLevel.VIEW)),
):
    return (
        db.query(Warehouse)
        .filter(Warehouse.company_id == current_user.company_id)
        .order_by(Warehouse.code)
        .all()
    )


@router.post("/warehouses", response_model=WarehouseOut, status_code=201)
def create_warehouse(
    body: WarehouseCreate,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access("stock_master", AccessLevel.FULL)),
):
    wh = Warehouse(company_id=current_user.company_id, **body.model_dump())
    db.add(wh)
    db.commit()
    db.refresh(wh)
    return wh


@router.patch("/warehouses/{wh_id}", response_model=WarehouseOut)
def update_warehouse(
    wh_id: uuid.UUID,
    body: WarehouseCreate,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access("stock_master", AccessLevel.FULL)),
):
    wh = db.query(Warehouse).filter(Warehouse.id == wh_id, Warehouse.company_id == current_user.company_id).first()
    if not wh:
        raise HTTPException(404, "Warehouse not found")
    for k, v in body.model_dump(exclude_unset=True).items():
        setattr(wh, k, v)
    db.commit()
    db.refresh(wh)
    return wh


# ━━ Stock Items ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

@router.get("/items", response_model=list[StockItemOut])
def list_stock_items(
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access("stock_master", AccessLevel.VIEW)),
):
    return (
        db.query(StockItem)
        .filter(StockItem.company_id == current_user.company_id)
        .order_by(StockItem.code)
        .all()
    )


@router.post("/items", response_model=StockItemOut, status_code=201)
def create_stock_item(
    body: StockItemCreate,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access("stock_master", AccessLevel.FULL)),
):
    item = StockItem(company_id=current_user.company_id, **body.model_dump())
    db.add(item)
    db.commit()
    db.refresh(item)
    return item


@router.patch("/items/{item_id}", response_model=StockItemOut)
def update_stock_item(
    item_id: uuid.UUID,
    body: StockItemCreate,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access("stock_master", AccessLevel.FULL)),
):
    item = db.query(StockItem).filter(StockItem.id == item_id, StockItem.company_id == current_user.company_id).first()
    if not item:
        raise HTTPException(404, "Stock item not found")
    for k, v in body.model_dump(exclude_unset=True).items():
        setattr(item, k, v)
    db.commit()
    db.refresh(item)
    return item


@router.get("/items/{item_id}", response_model=StockItemOut)
def get_stock_item(
    item_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access("stock_master", AccessLevel.VIEW)),
):
    item = db.query(StockItem).filter(StockItem.id == item_id, StockItem.company_id == current_user.company_id).first()
    if not item:
        raise HTTPException(404, "Stock item not found")
    return item


# ━━ Stock Levels ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

@router.get("/levels", response_model=list[StockLevelOut])
def list_stock_levels(
    warehouse_id: uuid.UUID | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access("stock_master", AccessLevel.VIEW)),
):
    q = (
        db.query(
            StockLevel,
            StockItem.code.label("item_code"),
            StockItem.name.label("item_name"),
            Warehouse.code.label("warehouse_code"),
            Warehouse.name.label("warehouse_name"),
        )
        .join(StockItem, StockLevel.stock_item_id == StockItem.id)
        .join(Warehouse, StockLevel.warehouse_id == Warehouse.id)
        .filter(StockLevel.company_id == current_user.company_id)
    )
    if warehouse_id:
        q = q.filter(StockLevel.warehouse_id == warehouse_id)
    rows = q.order_by(StockItem.code, Warehouse.code).all()
    result = []
    for sl, ic, iname, wc, wname in rows:
        out = StockLevelOut.model_validate(sl)
        out.item_code = ic
        out.item_name = iname
        out.warehouse_code = wc
        out.warehouse_name = wname
        result.append(out)
    return result


# ━━ Stock Movements (read-only journal) ━━━━━━━━━━━━━━━━━━━━━━━━━━

@router.get("/movements", response_model=list[StockMovementOut])
def list_stock_movements(
    stock_item_id: uuid.UUID | None = None,
    warehouse_id: uuid.UUID | None = None,
    limit: int = 200,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access("stock_operation_reports", AccessLevel.VIEW)),
):
    q = db.query(StockMovement).filter(StockMovement.company_id == current_user.company_id)
    if stock_item_id:
        q = q.filter(StockMovement.stock_item_id == stock_item_id)
    if warehouse_id:
        q = q.filter(StockMovement.warehouse_id == warehouse_id)
    return q.order_by(StockMovement.created_at.desc()).limit(limit).all()


# ━━ Goods Receive Note (GRN) ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

def _next_grn(db: Session, company_id: uuid.UUID) -> str:
    cnt = db.query(sqlfunc.count(GoodsReceiveNote.id)).filter(GoodsReceiveNote.company_id == company_id).scalar() or 0
    return f"GRN-{cnt + 1:05d}"


@router.get("/grn", response_model=list[GRNOut])
def list_grns(
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access("goods_receive_note", AccessLevel.VIEW)),
):
    return (
        db.query(GoodsReceiveNote)
        .options(selectinload(GoodsReceiveNote.lines))
        .filter(GoodsReceiveNote.company_id == current_user.company_id)
        .order_by(GoodsReceiveNote.created_at.desc())
        .all()
    )


@router.get("/grn/{grn_id}", response_model=GRNOut)
def get_grn(
    grn_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access("goods_receive_note", AccessLevel.VIEW)),
):
    grn = (
        db.query(GoodsReceiveNote)
        .options(selectinload(GoodsReceiveNote.lines))
        .filter(GoodsReceiveNote.id == grn_id, GoodsReceiveNote.company_id == current_user.company_id)
        .first()
    )
    if not grn:
        raise HTTPException(404, "GRN not found")
    return grn


@router.post("/grn", response_model=GRNOut, status_code=201)
def create_grn(
    body: GRNCreate,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access("goods_receive_note", AccessLevel.FULL)),
):
    grn = GoodsReceiveNote(
        company_id=current_user.company_id,
        grn_number=_next_grn(db, current_user.company_id),
        warehouse_id=body.warehouse_id,
        supplier_id=body.supplier_id,
        purchase_order_id=body.purchase_order_id,
        receive_date=body.receive_date or datetime.now(timezone.utc),
        notes=body.notes,
        created_by=current_user.id,
    )
    for ln in body.lines:
        line = GoodsReceiveNoteLine(
            stock_item_id=ln.stock_item_id,
            quantity=ln.quantity,
            unit_cost=Decimal(str(ln.unit_cost)),
            total_cost=(Decimal(str(ln.unit_cost)) * ln.quantity).quantize(Decimal("0.01")),
        )
        grn.lines.append(line)
    db.add(grn)
    db.commit()
    db.refresh(grn)
    return grn


@router.post("/grn/{grn_id}/confirm", response_model=GRNOut)
def confirm_grn(
    grn_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access("goods_receive_note", AccessLevel.FULL)),
):
    grn = (
        db.query(GoodsReceiveNote)
        .options(selectinload(GoodsReceiveNote.lines))
        .filter(GoodsReceiveNote.id == grn_id, GoodsReceiveNote.company_id == current_user.company_id)
        .first()
    )
    if not grn:
        raise HTTPException(404, "GRN not found")
    try:
        inv_svc.confirm_grn(db, grn, current_user.id)
    except ValueError as e:
        raise HTTPException(400, str(e))
    db.commit()
    db.refresh(grn)
    return grn


# ━━ Goods Transfer Note (GTN) ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

def _next_gtn(db: Session, company_id: uuid.UUID) -> str:
    cnt = db.query(sqlfunc.count(GoodsTransferNote.id)).filter(GoodsTransferNote.company_id == company_id).scalar() or 0
    return f"GTN-{cnt + 1:05d}"


@router.get("/gtn", response_model=list[GTNOut])
def list_gtns(
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access("goods_transfer_note", AccessLevel.VIEW)),
):
    return (
        db.query(GoodsTransferNote)
        .options(selectinload(GoodsTransferNote.lines))
        .filter(GoodsTransferNote.company_id == current_user.company_id)
        .order_by(GoodsTransferNote.created_at.desc())
        .all()
    )


@router.post("/gtn", response_model=GTNOut, status_code=201)
def create_gtn(
    body: GTNCreate,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access("goods_transfer_note", AccessLevel.FULL)),
):
    if body.from_warehouse_id == body.to_warehouse_id:
        raise HTTPException(400, "Source and destination warehouse must be different")
    gtn = GoodsTransferNote(
        company_id=current_user.company_id,
        gtn_number=_next_gtn(db, current_user.company_id),
        from_warehouse_id=body.from_warehouse_id,
        to_warehouse_id=body.to_warehouse_id,
        transfer_date=body.transfer_date or datetime.now(timezone.utc),
        notes=body.notes,
        created_by=current_user.id,
    )
    for ln in body.lines:
        gtn.lines.append(GoodsTransferNoteLine(stock_item_id=ln.stock_item_id, quantity=ln.quantity, notes=ln.notes))
    db.add(gtn)
    db.commit()
    db.refresh(gtn)
    return gtn


@router.post("/gtn/{gtn_id}/confirm", response_model=GTNOut)
def confirm_gtn(
    gtn_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access("goods_transfer_note", AccessLevel.FULL)),
):
    gtn = (
        db.query(GoodsTransferNote)
        .options(selectinload(GoodsTransferNote.lines))
        .filter(GoodsTransferNote.id == gtn_id, GoodsTransferNote.company_id == current_user.company_id)
        .first()
    )
    if not gtn:
        raise HTTPException(404, "GTN not found")
    try:
        inv_svc.confirm_gtn(db, gtn, current_user.id)
    except ValueError as e:
        raise HTTPException(400, str(e))
    db.commit()
    db.refresh(gtn)
    return gtn


# ━━ Goods Return Note (GRTN) ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

def _next_grtn(db: Session, company_id: uuid.UUID) -> str:
    cnt = db.query(sqlfunc.count(GoodsReturnNote.id)).filter(GoodsReturnNote.company_id == company_id).scalar() or 0
    return f"GRTN-{cnt + 1:05d}"


@router.get("/grtn", response_model=list[GRTNOut])
def list_grtns(
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access("goods_return_note", AccessLevel.VIEW)),
):
    return (
        db.query(GoodsReturnNote)
        .options(selectinload(GoodsReturnNote.lines))
        .filter(GoodsReturnNote.company_id == current_user.company_id)
        .order_by(GoodsReturnNote.created_at.desc())
        .all()
    )


@router.post("/grtn", response_model=GRTNOut, status_code=201)
def create_grtn(
    body: GRTNCreate,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access("goods_return_note", AccessLevel.FULL)),
):
    grtn = GoodsReturnNote(
        company_id=current_user.company_id,
        grtn_number=_next_grtn(db, current_user.company_id),
        warehouse_id=body.warehouse_id,
        supplier_id=body.supplier_id,
        return_date=body.return_date or datetime.now(timezone.utc),
        reason=body.reason,
        notes=body.notes,
        created_by=current_user.id,
    )
    for ln in body.lines:
        grtn.lines.append(GoodsReturnNoteLine(
            stock_item_id=ln.stock_item_id,
            quantity=ln.quantity,
            unit_cost=Decimal(str(ln.unit_cost)),
            total_cost=(Decimal(str(ln.unit_cost)) * ln.quantity).quantize(Decimal("0.01")),
        ))
    db.add(grtn)
    db.commit()
    db.refresh(grtn)
    return grtn


@router.post("/grtn/{grtn_id}/confirm", response_model=GRTNOut)
def confirm_grtn(
    grtn_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access("goods_return_note", AccessLevel.FULL)),
):
    grtn = (
        db.query(GoodsReturnNote)
        .options(selectinload(GoodsReturnNote.lines))
        .filter(GoodsReturnNote.id == grtn_id, GoodsReturnNote.company_id == current_user.company_id)
        .first()
    )
    if not grtn:
        raise HTTPException(404, "GRTN not found")
    try:
        inv_svc.confirm_grtn(db, grtn, current_user.id)
    except ValueError as e:
        raise HTTPException(400, str(e))
    db.commit()
    db.refresh(grtn)
    return grtn


# ━━ Stock Adjustment ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

def _next_adj(db: Session, company_id: uuid.UUID) -> str:
    cnt = db.query(sqlfunc.count(StockAdjustment.id)).filter(StockAdjustment.company_id == company_id).scalar() or 0
    return f"ADJ-{cnt + 1:05d}"


@router.get("/adjustments", response_model=list[AdjustmentOut])
def list_adjustments(
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access("stock_adjustment", AccessLevel.VIEW)),
):
    return (
        db.query(StockAdjustment)
        .options(selectinload(StockAdjustment.lines))
        .filter(StockAdjustment.company_id == current_user.company_id)
        .order_by(StockAdjustment.created_at.desc())
        .all()
    )


@router.post("/adjustments", response_model=AdjustmentOut, status_code=201)
def create_adjustment(
    body: AdjustmentCreate,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access("stock_adjustment", AccessLevel.FULL)),
):
    adj = StockAdjustment(
        company_id=current_user.company_id,
        adj_number=_next_adj(db, current_user.company_id),
        warehouse_id=body.warehouse_id,
        adjustment_date=body.adjustment_date or datetime.now(timezone.utc),
        reason=body.reason,
        created_by=current_user.id,
    )
    for ln in body.lines:
        adj.lines.append(StockAdjustmentLine(
            stock_item_id=ln.stock_item_id,
            quantity_change=ln.quantity_change,
            notes=ln.notes,
        ))
    db.add(adj)
    db.commit()
    db.refresh(adj)
    return adj


@router.post("/adjustments/{adj_id}/submit", response_model=AdjustmentOut)
def submit_adjustment(
    adj_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access("stock_adjustment", AccessLevel.FULL)),
):
    """Submit for approval (INV-001)."""
    adj = (
        db.query(StockAdjustment)
        .options(selectinload(StockAdjustment.lines))
        .filter(StockAdjustment.id == adj_id, StockAdjustment.company_id == current_user.company_id)
        .first()
    )
    if not adj:
        raise HTTPException(404, "Adjustment not found")
    if adj.status != AdjustmentStatus.draft:
        raise HTTPException(400, "Only draft adjustments can be submitted")
    adj.status = AdjustmentStatus.pending_approval
    db.commit()
    db.refresh(adj)
    return adj


@router.post("/adjustments/{adj_id}/approve", response_model=AdjustmentOut)
def approve_adjustment(
    adj_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access("stock_adjustment", AccessLevel.FULL)),
):
    """INV-001: manager approves — stock changes take effect."""
    adj = (
        db.query(StockAdjustment)
        .options(selectinload(StockAdjustment.lines))
        .filter(StockAdjustment.id == adj_id, StockAdjustment.company_id == current_user.company_id)
        .first()
    )
    if not adj:
        raise HTTPException(404, "Adjustment not found")
    try:
        inv_svc.approve_adjustment(db, adj, current_user.id)
    except ValueError as e:
        raise HTTPException(400, str(e))
    db.commit()
    db.refresh(adj)
    return adj


@router.post("/adjustments/{adj_id}/reject", response_model=AdjustmentOut)
def reject_adjustment(
    adj_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access("stock_adjustment", AccessLevel.FULL)),
):
    adj = (
        db.query(StockAdjustment)
        .options(selectinload(StockAdjustment.lines))
        .filter(StockAdjustment.id == adj_id, StockAdjustment.company_id == current_user.company_id)
        .first()
    )
    if not adj:
        raise HTTPException(404, "Adjustment not found")
    if adj.status != AdjustmentStatus.pending_approval:
        raise HTTPException(400, "Only pending adjustments can be rejected")
    adj.status = AdjustmentStatus.rejected
    db.commit()
    db.refresh(adj)
    return adj


# ━━ Stock Reports ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

@router.get("/reports/valuation")
def stock_valuation_report(
    warehouse_id: uuid.UUID | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access("stock_operation_reports", AccessLevel.VIEW)),
):
    """Stock valuation: qty * avg_cost per item per warehouse."""
    q = (
        db.query(
            StockLevel.stock_item_id,
            StockItem.code,
            StockItem.name,
            StockLevel.warehouse_id,
            Warehouse.code.label("warehouse_code"),
            Warehouse.name.label("warehouse_name"),
            StockLevel.quantity,
            StockLevel.avg_cost,
        )
        .join(StockItem, StockLevel.stock_item_id == StockItem.id)
        .join(Warehouse, StockLevel.warehouse_id == Warehouse.id)
        .filter(StockLevel.company_id == current_user.company_id, StockLevel.quantity > 0)
    )
    if warehouse_id:
        q = q.filter(StockLevel.warehouse_id == warehouse_id)
    rows = q.order_by(StockItem.code, Warehouse.code).all()
    total_value = Decimal("0")
    items = []
    for r in rows:
        val = Decimal(r.quantity) * r.avg_cost
        total_value += val
        items.append({
            "item_code": r.code,
            "item_name": r.name,
            "warehouse_code": r.warehouse_code,
            "warehouse_name": r.warehouse_name,
            "quantity": r.quantity,
            "avg_cost": float(r.avg_cost),
            "total_value": float(val.quantize(Decimal("0.01"))),
        })
    return {"items": items, "total_value": float(total_value.quantize(Decimal("0.01")))}


@router.get("/reports/reorder")
def reorder_report(
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access("stock_operation_reports", AccessLevel.VIEW)),
):
    """Items whose total stock across all warehouses is at or below reorder level."""
    items = (
        db.query(StockItem)
        .filter(StockItem.company_id == current_user.company_id, StockItem.is_active == True, StockItem.reorder_level > 0)
        .all()
    )
    result = []
    for item in items:
        total_qty = (
            db.query(sqlfunc.coalesce(sqlfunc.sum(StockLevel.quantity), 0))
            .filter(StockLevel.stock_item_id == item.id)
            .scalar()
        )
        if total_qty <= item.reorder_level:
            result.append({
                "item_code": item.code,
                "item_name": item.name,
                "unit_of_measure": item.unit_of_measure,
                "reorder_level": item.reorder_level,
                "current_stock": total_qty,
                "shortfall": item.reorder_level - total_qty,
            })
    return result
