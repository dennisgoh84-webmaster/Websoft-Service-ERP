"""Product/Service Catalog.

Confirmed 2026-09-10 from the Odoo Products screens Dennis shared: a
catalog of sellable items a Sales Quotation line can be drawn from,
instead of free text, with Product Category, Internal Reference, Sales
Price, Cost, Unit of Measure, Tags and a Customer Tax code.

Several fields visible on those Odoo screens are deliberately left out
of this first build -- Can be Sold/Purchased, Invoicing Policy, "Create
on Order", recurring-price schedules, Purchase UoM, Barcode -- because
this system has no automated recurring-billing engine yet, so those
controls would have nothing to drive. Add them if/when that exists.
"""
import enum
import uuid
from datetime import datetime
from decimal import Decimal

from sqlalchemy import Boolean, DateTime, Enum, ForeignKey, Numeric, String, func
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base
from app.models.tax import DEFAULT_TAX_CODE


class ProductType(str, enum.Enum):
    service = "service"
    product = "product"


class Product(Base):
    __tablename__ = "products"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("companies.id"), nullable=False)
    product_type: Mapped[ProductType] = mapped_column(
        Enum(ProductType, name="product_type"), nullable=False, default=ProductType.service
    )
    name: Mapped[str] = mapped_column(String(255), nullable=False)
    internal_reference: Mapped[str | None] = mapped_column(String(50), nullable=True)
    product_category: Mapped[str | None] = mapped_column(String(255), nullable=True)
    # Simple free-text, comma-separated -- matches CompanyIndividual.tags (no
    # dedicated tag table exists anywhere in this system yet).
    tags: Mapped[str | None] = mapped_column(String(255), nullable=True)
    sales_price_sgd: Mapped[Decimal] = mapped_column(Numeric(12, 2), nullable=False, default=0)
    cost_sgd: Mapped[Decimal | None] = mapped_column(Numeric(12, 2), nullable=True)
    # Free text (e.g. "Monthly", "Yearly", "Hours", "Units", "SET") --
    # not a fixed enum, since the Odoo reference list wasn't closed.
    unit_of_measure: Mapped[str | None] = mapped_column(String(50), nullable=True)
    # The GST tax code this item sells under, e.g. "SR" -- looked up
    # against the company's current TaxCode at the time a quotation/
    # invoice line is raised (app/services/tax.py), not locked here.
    tax_code: Mapped[str] = mapped_column(String(10), nullable=False, default=DEFAULT_TAX_CODE)
    # Reference Monitor (2026-09-12): the GL sub-code (see
    # app/models/reference_codes.py) a document line defaults to when
    # this product is picked -- e.g. a "Websoft Implementation" service
    # item defaulting to SLS-WEBSOFT-IMPLEMENTATION under GL 45001.
    # Optional and overridable per line, never required.
    default_reference_code_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("reference_codes.id"), nullable=True
    )
    # 2026-09-12: flags this product as a stock (inventory) item.  The
    # full Stock Master module lives in a separate Websoft Stock
    # Distribution ERP project; when that's ready the two will be linked.
    # Until then this is a simple flag for reporting / filtering.
    is_stock: Mapped[bool] = mapped_column(Boolean, nullable=False, default=False)
    is_active: Mapped[bool] = mapped_column(Boolean, nullable=False, default=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
