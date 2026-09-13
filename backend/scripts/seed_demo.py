"""
Seed a clean demo dataset for the Websoft Service ERP Solution
Service Operations core slice.

Run with: uv run python scripts/seed_demo.py

Sets up:
- Company: Webmaster Consultancy Pte Ltd
- Module catalog + per-company module enablement (Module Control /
  licensing) -- Service Operations core modules enabled, everything
  else (including Commission Management and Integrations/Odoo
  migration, deferred for now) left disabled.
- Users: Dennis (owner), Nico (service_lead), Cherish (sales_manager),
  Wei Ling (support_engineer) -- all password "demo1234"
- Group Authority: default Groups (Owner / Admin, Service Team, Sales
  Team, Finance Team) with a per-module access matrix, and each seeded
  user assigned to the appropriate Group. Dennis's OWNER role already
  grants him FULL access everywhere regardless of group (see
  app/services/authority.py); he is still put in "Owner / Admin" so
  Staff Master doesn't show him as ungrouped. Nico and Wei Ling both
  land in "Service Team" -- the group grants them broad Service
  Operations/Contracts access, but the SRV-004/SRV-011 named-person
  rule (Nico, or Cherish as backup, decides excess usage) is enforced
  separately by role, not by group, so Wei Ling still can't approve a
  Service Record or decide excess usage despite sharing Nico's group.
- Company/Individual: Acme Manufacturing Pte Ltd
- One Active 10-hour contract (SGD 3,000), already invoiced annually
- A job order with Service Records already approved, deliberately left
  just short of exhausting the contract, PLUS one final SUBMITTED
  (not yet approved) record that will push it into Excess Usage --
  left pending on purpose so a live walkthrough can approve it and
  perform the Nico excess-usage review itself, rather than the seed
  script doing it upfront.
"""
import base64
import struct
import sys
import zlib
from datetime import date, datetime, timedelta, timezone
from decimal import Decimal
from pathlib import Path

sys.path.append(str(Path(__file__).resolve().parents[1]))

from sqlalchemy import text

from app.core.database import Base, SessionLocal, engine
from app.models.core import Company, User, UserCompanyAccess, UserRole
from app.models.company_individuals import Branch, Contact, CompanyIndividual, CompanyIndividualGroup, CompanyIndividualType
from app.models.groups import AccessLevel, Group, GroupModuleAuthority
from app.models.job_orders import JobOrder, JobOrderPriority, JobOrderStatus
from app.models.licensing import CompanyModule, LicenseType, Module
from app.models.accounting import Account, AccountType, GLType
from app.models.setup import SetupListItem, SetupListType
from app.models.announcements import AdBannerSettings, Announcement
from app.models.treasury import BankAccount, CurrencyRate
from app.models.payables import (
    PurchaseOrder,
    PurchaseOrderStatus,
    SupplierInvoice,
    SupplierPayment,
)
from app.models.tax import TaxCode
from app.models.catalog import Product, ProductType
from app.models.quotations import Quotation, QuotationLine, QuotationStatus
from app.models.software_tasks import SoftwareTask
from app.models.ops_tasks import OpsTask, OpsTaskCategory, OpsTaskStatus
from app.services import payables as ap_svc
from app.services import billing as billing_svc
from app.services import contracts as contract_svc
from app.services import quotations as quotation_svc
from app.services import service_records as sr_svc
from app.services.auth import hash_password
from app.services.numbering import next_document_number
from app.services.tax import apply_gst

DEMO_PASSWORD = "demo1234"
# Seeded accounts are pre-provisioned for a live demo/walkthrough, so
# every one of them is created with must_change_password=False --
# skipping the forced-first-login password change (2026-09-12) real
# staff accounts (created from Staff Master) always go through.

# key -> (name, description, is_built, enabled_by_default)
# Covers the 19 business-area modules in docs/module-map.md, plus
# "event_logs" -- a Core / Administration capability (the Event Logs
# master, see app/routers/event_logs.py) broken out as its own module
# key so Group Authority can gate it independently of general admin
# access. Commission Management and Integrations (which owns future
# Odoo migration work) are listed for completeness of the control plane
# but left disabled/not built -- deferred at the user's request until
# Service Operations is finalized.
MODULE_CATALOG = [
    ("core_administration", "Core / Administration", True, True),
    ("event_logs", "Event Logs", True, True),
    ("crm", "CRM", False, False),
    ("sales", "Sales (Quotations, Product/Service Catalog)", True, True),
    ("company_individual_management", "Customer Management", True, True),
    ("service_contracts", "Service Contracts", True, True),
    ("service_operations", "Helpdesk / Service Operations (Job Orders)", True, True),
    ("projects", "Projects", False, False),
    ("service_records", "Service Records", True, True),
    ("billing", "Billing", True, True),
    ("accounts_receivable", "Accounts Receivable", True, True),
    ("accounts_payable", "Accounts Payable", True, True),
    ("purchasing", "Purchasing", True, True),
    ("inventory", "Inventory", False, False),
    ("hardware_management", "Hardware Management", False, False),
    ("commission_management", "Commission Management", False, False),  # deferred
    ("finance_accounting", "Finance / Accounting", True, True),
    ("reporting", "Reporting / Management Dashboard", True, True),
    ("software_development", "Software Development (Software Tasks)", True, True),
    # Separate from "reporting" (which is Support Monitoring's dashboard)
    # so Finance can be granted Accounting Reports without also getting
    # Support Monitoring, and vice versa for Service/Sales -- least
    # privilege per module, not one shared reporting bucket.
    ("operations_reports", "Operations Reports (Contracts / Job Orders / Service Records)", True, True),
    ("accounting_reports", "Accounting Reports (AR/AP Aging, Trial Balance)", True, True),
    ("integrations", "Integrations (incl. Odoo migration)", False, False),  # deferred
    ("ai_assistant", "AI Assistant", False, False),
    # Stock / Inventory sub-modules (confirmed 2026-09-13) -- not yet
    # built; listed so Module Control / Client Control can license them
    # and Group Authority can gate access once they are implemented.
    ("stock_master", "Stock Master", False, False),
    ("goods_receive_note", "Goods Receive Note", False, False),
    ("goods_transfer_note", "Goods Transfer Note", False, False),
    ("goods_return_note", "Goods Return Note", False, False),
    ("stock_adjustment", "Stock Adjustment", False, False),
    ("stock_operation_reports", "Stock Operation Reports", False, False),
    # Personal, freeform task tracker per staff member (confirmed
    # 2026-09-11) -- everyone gets FULL on their own dashboard; see
    # GROUP_CATALOG below and app/routers/ops_dashboard.py for the
    # separate manager-role check that lets Owner/Service Lead/Sales
    # Manager view another staff member's dashboard too.
    ("ops_dashboard", "Ops Dashboard (personal task tracker)", True, True),
]


# Default Groups and their per-module access matrix (Group Authority).
# name -> description -> {module_key: AccessLevel}. Modules not listed
# for a group default to AccessLevel.NONE (see GroupModuleAuthority).
NONE, VIEW, EDIT, FULL = AccessLevel.NONE, AccessLevel.VIEW, AccessLevel.EDIT, AccessLevel.FULL

GROUP_CATALOG = {
    "Owner / Admin": (
        "Full access to every module. Dennis's OWNER role already grants this "
        "regardless of group -- this group exists so Staff Master shows him as "
        "grouped, and as the template for any future admin hires.",
        {
            key: FULL
            for key in (
                "core_administration",
                "event_logs",
                "company_individual_management",
                "accounts_receivable",
                "accounts_payable",
                "purchasing",
                "finance_accounting",
                "service_contracts",
                "service_operations",
                "service_records",
                "billing",
                "sales",
                "reporting",
                "software_development",
                "operations_reports",
                "accounting_reports",
                "ops_dashboard",
            )
        },
    ),
    "Service Team": (
        "Nico (Service & Support Lead) and the support engineers who log and "
        "resolve Job Orders and submit/approve Service Records.",
        {
            "service_operations": FULL,
            "service_records": FULL,
            "service_contracts": FULL,  # incl. excess-usage review; SRV-004 role check still applies
            "company_individual_management": VIEW,
            "billing": VIEW,
            "operations_reports": VIEW,
            "core_administration": NONE,
            "event_logs": NONE,  # system-wide audit trail -- Owner/Admin only by default
            "ops_dashboard": FULL,
        },
    ),
    "Sales Team": (
        "Cherish (Sales Manager) -- owns customers and contracts, and is the "
        "SRV-004/SRV-011 backup decider for excess usage.",
        {
            "company_individual_management": FULL,
            "service_contracts": FULL,
            "sales": FULL,
            "service_operations": VIEW,
            "service_records": VIEW,
            "billing": VIEW,
            "accounts_receivable": VIEW,
            "operations_reports": VIEW,
            "accounting_reports": VIEW,
            "core_administration": NONE,
            "event_logs": NONE,
            "ops_dashboard": FULL,
        },
    ),
    "Finance Team": (
        "Billing, invoicing, Accounts Receivable, Accounts Payable and the "
        "general ledger -- records customer payments and allocates them "
        "(AR-001), pays suppliers, raises purchase orders, and posts "
        "Journal Vouchers.",
        {
            "billing": FULL,
            "accounts_receivable": FULL,
            "accounts_payable": FULL,
            "purchasing": FULL,
            "finance_accounting": FULL,
            "service_contracts": VIEW,
            # FULL, not VIEW (2026-09-12): suppliers are now Company/
            # Individual records (is_supplier=True) rather than a
            # separate AP-only master, so onboarding a new supplier
            # needs edit rights here, same as accounts_payable FULL
            # implied before the merge.
            "company_individual_management": FULL,
            "sales": VIEW,
            "accounting_reports": FULL,
            "service_operations": NONE,
            "service_records": NONE,
            "core_administration": NONE,
            "event_logs": NONE,
            "ops_dashboard": FULL,
        },
    ),
}


def seed_groups(db, company: Company) -> dict[str, Group]:
    groups = {}
    for name, (description, matrix) in GROUP_CATALOG.items():
        group = Group(company_id=company.id, name=name, description=description)
        db.add(group)
        db.flush()
        for module_key, access_level in matrix.items():
            db.add(
                GroupModuleAuthority(
                    group_id=group.id, module_key=module_key, access_level=access_level
                )
            )
        groups[name] = group
    db.flush()
    return groups


def wipe_data(db):
    """Truncate all app tables for a clean, repeatable demo reset."""
    table_names = [t.name for t in reversed(Base.metadata.sorted_tables)]
    if table_names:
        db.execute(text(f"TRUNCATE TABLE {', '.join(table_names)} RESTART IDENTITY CASCADE"))
    db.commit()


def seed_module_catalog(db):
    """The Module catalog is global (the list of business areas that
    exist); which of them a given company runs is CompanyModule below."""
    for key, name, is_built, _enabled in MODULE_CATALOG:
        db.add(Module(key=key, name=name, is_built=is_built))
    db.flush()


def seed_company_modules(db, company: Company, disabled_keys: set[str] = frozenset()):
    """Per-company module enablement. `disabled_keys` lets a second
    company run a different module mix from the first -- the whole point
    of Module Control being per-company."""
    for key, _name, _is_built, enabled in MODULE_CATALOG:
        on = enabled and key not in disabled_keys
        db.add(
            CompanyModule(
                company_id=company.id,
                module_key=key,
                enabled=on,
                license_type=LicenseType.INCLUDED,
                enabled_at=datetime.now(timezone.utc) if on else None,
            )
        )
    db.flush()


# GST tax codes. Confirmed 2026-09-10: Webmaster is GST-registered and
# its services are standard-rated (SR). The others are seeded inactive-
# ready so a future zero-rated/exempt supply doesn't need a code change.
TAX_CODES = [
    ("SR", "Standard-rated supply", Decimal("9.00")),
    ("ZR", "Zero-rated supply (e.g. export of services)", Decimal("0.00")),
    ("ES", "Exempt supply", Decimal("0.00")),
    ("OS", "Out of scope", Decimal("0.00")),
]


def seed_tax_codes(db, company: Company):
    for code, name, rate in TAX_CODES:
        db.add(
            TaxCode(company_id=company.id, code=code, name=name, rate_percent=rate, is_active=True)
        )
    db.flush()


# A conventional Singapore SME chart of accounts, seeded as a STARTING
# POINT (confirmed approach with Dennis, 2026-09-10) -- not a decided
# chart. Every line can be renamed, added to or retired from the Chart
# of Accounts screen. Nothing posts to these yet; GL posting arrives
# with the Finance / Accounting module, so no assumption is made here
# about which account a given transaction hits.
CHART_OF_ACCOUNTS = [
    # Assets (1xxx)
    ("1000", "Cash at bank", AccountType.ASSET),
    ("1010", "Petty cash", AccountType.ASSET),
    ("1100", "Accounts receivable", AccountType.ASSET),
    ("1150", "Accrued revenue", AccountType.ASSET),
    ("1200", "Prepayments", AccountType.ASSET),
    ("1300", "Inventory", AccountType.ASSET),
    ("1500", "Office equipment", AccountType.ASSET),
    ("1510", "Accumulated depreciation -- office equipment", AccountType.ASSET),
    # Liabilities (2xxx)
    ("2000", "Accounts payable", AccountType.LIABILITY),
    ("2100", "GST output tax (collected on sales)", AccountType.LIABILITY),
    ("2110", "GST input tax (paid on purchases)", AccountType.LIABILITY),
    ("2200", "Accruals", AccountType.LIABILITY),
    ("2300", "Deferred revenue (unearned contract income)", AccountType.LIABILITY),
    ("2400", "CPF payable", AccountType.LIABILITY),
    ("2500", "Corporate tax payable", AccountType.LIABILITY),
    # Equity (3xxx)
    ("3000", "Share capital", AccountType.EQUITY),
    ("3100", "Retained earnings", AccountType.EQUITY),
    # Revenue (4xxx)
    ("4000", "Service contract revenue", AccountType.REVENUE),
    ("4010", "Excess usage revenue", AccountType.REVENUE),
    ("4020", "Project revenue", AccountType.REVENUE),
    ("4030", "Hardware sales", AccountType.REVENUE),
    ("4900", "Other income", AccountType.REVENUE),
    # Expenses (5xxx-6xxx)
    ("5000", "Cost of services", AccountType.EXPENSE),
    ("5010", "Cost of hardware sold", AccountType.EXPENSE),
    ("5020", "Subcontractor costs", AccountType.EXPENSE),
    ("6000", "Salaries and wages", AccountType.EXPENSE),
    ("6010", "CPF contributions", AccountType.EXPENSE),
    ("6100", "Rent", AccountType.EXPENSE),
    ("6110", "Utilities", AccountType.EXPENSE),
    ("6200", "Software and subscriptions", AccountType.EXPENSE),
    ("6300", "Professional fees", AccountType.EXPENSE),
    ("6400", "Marketing", AccountType.EXPENSE),
    ("6500", "Bank charges", AccountType.EXPENSE),
    ("6600", "Depreciation", AccountType.EXPENSE),
    ("6700", "Bad debts written off", AccountType.EXPENSE),
    ("6900", "Other operating expenses", AccountType.EXPENSE),
]


def seed_chart_of_accounts(db, company: Company):
    for code, name, account_type in CHART_OF_ACCOUNTS:
        db.add(
            Account(
                company_id=company.id, code=code, name=name, account_type=account_type
            )
        )
    db.flush()


# Global reference data (Setup Lists), shared by every company -- see
# app/models/setup.py. A starting set, not an exhaustive world list;
# more can be added from the Setup Lists screen as needed.
SETUP_LIST_ITEMS = [
    (SetupListType.COUNTRY, "SG", "Singapore", None),
    (SetupListType.COUNTRY, "MY", "Malaysia", None),
    (SetupListType.COUNTRY, "ID", "Indonesia", None),
    (SetupListType.COUNTRY, "US", "United States", None),
    (SetupListType.COUNTRY, "GB", "United Kingdom", None),
    (SetupListType.COUNTRY, "AU", "Australia", None),
    (SetupListType.COUNTRY, "CN", "China", None),
    (SetupListType.STATE, "JHR", "Johor", "MY"),
    (SetupListType.STATE, "SEL", "Selangor", "MY"),
    (SetupListType.STATE, "KUL", "Kuala Lumpur", "MY"),
    (SetupListType.NATIONALITY, "SGP", "Singaporean", None),
    (SetupListType.NATIONALITY, "MYS", "Malaysian", None),
    (SetupListType.NATIONALITY, "IDN", "Indonesian", None),
    (SetupListType.NATIONALITY, "CHN", "Chinese", None),
    (SetupListType.NATIONALITY, "IND", "Indian", None),
    (SetupListType.AREA_CODE, "SG-CENTRAL", "Central Region", "SG"),
    (SetupListType.AREA_CODE, "SG-EAST", "East Region", "SG"),
    (SetupListType.AREA_CODE, "SG-WEST", "West Region", "SG"),
    (SetupListType.AREA_CODE, "SG-NORTH", "North Region", "SG"),
    (SetupListType.CURRENCY, "SGD", "Singapore Dollar", None),
    (SetupListType.CURRENCY, "USD", "US Dollar", None),
    (SetupListType.CURRENCY, "MYR", "Malaysian Ringgit", None),
    (SetupListType.CURRENCY, "EUR", "Euro", None),
    (SetupListType.CURRENCY, "GBP", "British Pound", None),
    (SetupListType.CURRENCY, "CNY", "Chinese Yuan", None),
    (SetupListType.CURRENCY, "AUD", "Australian Dollar", None),
    # Confirmed 2026-09-11: customer grouping by industry.
    (SetupListType.INDUSTRY, "MFG", "Manufacturing", None),
    (SetupListType.INDUSTRY, "LOGISTICS", "Logistics & Transportation", None),
    (SetupListType.INDUSTRY, "TECH", "Technology / Software", None),
    (SetupListType.INDUSTRY, "ENG", "Engineering", None),
    (SetupListType.INDUSTRY, "RETAIL", "Retail", None),
    (SetupListType.INDUSTRY, "PROF_SVC", "Professional Services", None),
]


def seed_setup_lists(db):
    for i, (list_type, code, name, parent_code) in enumerate(SETUP_LIST_ITEMS):
        db.add(
            SetupListItem(
                list_type=list_type, code=code, name=name, parent_code=parent_code, sort_order=i
            )
        )
    db.flush()


# The ad banner's default content (2026-09-12) -- global, not company-
# scoped (see app/models/announcements.py), so this is reseeded here
# alongside the other global catalogs (Setup Lists, Module catalog)
# rather than per-company. Matches whatever migration b3c4d5e6f7a8
# seeded, so a demo reseed restores the same starting point Dennis can
# then edit from the new Announcements admin screen.
DEFAULT_AD_VIDEO_URL = "https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4"

DEFAULT_ANNOUNCEMENTS = [
    ("New", "Reference Monitor: break one Chart of Accounts code into named sub-codes for Sales Quotation lines."),
    ("Add-on", "Print/Email/WhatsApp actions are now icon buttons across every document list."),
    ("Update", 'Company/Individual replaces the old "Customer" naming throughout the app.'),
]


def seed_announcements(db):
    db.add(AdBannerSettings(id=1, video_url=DEFAULT_AD_VIDEO_URL))
    for i, (tag, text) in enumerate(DEFAULT_ANNOUNCEMENTS):
        db.add(Announcement(tag=tag, text=text, sort_order=i))
    db.flush()


# A starting GL Type classification, matching the seeded Chart of
# Accounts -- purely a reporting label (see app/models/accounting.py).
GL_TYPES = [
    ("BANK", "Bank", AccountType.ASSET),
    ("CASH", "Cash", AccountType.ASSET),
    ("CURR_AST", "Current Asset", AccountType.ASSET),
    ("FIXED_AST", "Fixed Asset", AccountType.ASSET),
    ("CURR_LIAB", "Current Liability", AccountType.LIABILITY),
    ("EQUITY", "Equity", AccountType.EQUITY),
    ("OP_REVENUE", "Operating Revenue", AccountType.REVENUE),
    ("OP_EXPENSE", "Operating Expense", AccountType.EXPENSE),
    ("PAYROLL", "Payroll Expense", AccountType.EXPENSE),
]


def seed_gl_types(db, company: Company) -> dict[str, GLType]:
    gl_types = {}
    for code, name, account_type in GL_TYPES:
        gl_type = GLType(company_id=company.id, code=code, name=name, account_type=account_type)
        db.add(gl_type)
        gl_types[code] = gl_type
    db.flush()
    return gl_types


def seed_treasury(db, company: Company, cash_account: Account | None):
    """A demonstration Bank Master File entry and Currency Rate Table
    row -- setup data only, see app/models/treasury.py."""
    db.add(
        BankAccount(
            company_id=company.id,
            bank_name="DBS Bank",
            account_name=company.name,
            account_number="003-9-123456",
            branch="Raffles Place",
            swift_code="DBSSSGSG",
            currency_code="SGD",
            gl_account_id=cash_account.id if cash_account else None,
        )
    )
    db.add(
        CurrencyRate(
            company_id=company.id,
            currency_code="USD",
            rate_to_base=Decimal("1.35"),
            effective_date=date.today(),
        )
    )
    db.flush()


def logo_data_uri(initials: str, bg: str = "#7a1f2e") -> str:
    """A simple placeholder logo in the company colours (maroon/white),
    stored the same way an uploaded one is: an image data URI on the
    Company record. Dennis can replace it from Company Setup."""
    svg = (
        '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96">'
        f'<rect width="96" height="96" rx="20" fill="{bg}"/>'
        '<text x="48" y="63" font-family="system-ui,Segoe UI,Roboto,sans-serif" '
        f'font-size="36" font-weight="700" fill="#ffffff" text-anchor="middle">{initials}</text>'
        "</svg>"
    )
    return "data:image/svg+xml;base64," + base64.b64encode(svg.encode("utf-8")).decode("ascii")


def _png_chunk(chunk_type: bytes, data: bytes) -> bytes:
    return (
        struct.pack(">I", len(data))
        + chunk_type
        + data
        + struct.pack(">I", zlib.crc32(chunk_type + data) & 0xFFFFFFFF)
    )


def avatar_photo_data_uri(bg_hex: str, size: int = 160) -> str:
    """A placeholder staff photo -- confirmed 2026-09-11: sample photos
    for Support Monitoring's 8-staff demo view. NOT a real photograph:
    this environment's outbound network access is a small allowlist of
    code-library CDNs (no photo/stock-image host is reachable), and
    there is no image-generation tool available either, so an actual
    photo of a real or synthetic person cannot be produced here. This
    is a generic person-silhouette icon instead, encoded as a plain PNG
    with the stdlib only (zlib + struct -- CLAUDE.md: no unnecessary
    dependencies). It exercises the exact same User.photo field and
    rendering path a real uploaded photo would (Staff Master's own
    upload -- see StaffDetailPage.tsx -- produces a real photo data URI
    the same way); this is demo/seed data only, never application
    runtime code."""
    bg = tuple(int(bg_hex[i : i + 2], 16) for i in (0, 2, 4))
    fg = (245, 240, 235)

    cx, cy = size / 2, size * 0.40
    head_r = size * 0.17
    body_cx, body_cy, body_r = size / 2, size * 1.05, size * 0.42

    rows = bytearray()
    for y in range(size):
        rows.append(0)  # filter byte: None
        for x in range(size):
            in_head = (x - cx) ** 2 + (y - cy) ** 2 <= head_r**2
            in_body = y >= size * 0.62 and (x - body_cx) ** 2 + (y - body_cy) ** 2 <= body_r**2
            rows += bytes(fg if (in_head or in_body) else bg)

    ihdr = struct.pack(">IIBBBBB", size, size, 8, 2, 0, 0, 0)
    png = (
        b"\x89PNG\r\n\x1a\n"
        + _png_chunk(b"IHDR", ihdr)
        + _png_chunk(b"IDAT", zlib.compress(bytes(rows), 9))
        + _png_chunk(b"IEND", b"")
    )
    return "data:image/png;base64," + base64.b64encode(png).decode("ascii")


def file_data_uri(path: Path, mime: str) -> str:
    """The real Webmaster Consultancy logo, extracted from Dennis's own
    Quotation letterhead (Quote_0160, shared 2026-09-10) so printed
    forms match that reference exactly, rather than the SVG placeholder
    above."""
    return f"data:{mime};base64," + base64.b64encode(path.read_bytes()).decode("ascii")


WEBMASTER_LOGO_PATH = Path(__file__).parent / "assets" / "webmaster_logo.png"


def main():
    Base.metadata.create_all(bind=engine)  # no-op if migrations already applied
    db = SessionLocal()
    try:
        wipe_data(db)

        company = Company(
            name="Web Master Consultancy Pte Ltd",
            logo=(
                file_data_uri(WEBMASTER_LOGO_PATH, "image/png")
                if WEBMASTER_LOGO_PATH.exists()
                else logo_data_uri("WC")
            ),
            # Real letterhead details, from Dennis's own Quotation
            # (Quote_0160, shared 2026-09-10) -- editable in Company Setup.
            address="8 Ubi Road 2 #05-12/13/14 Zervex, Singapore 408538",
            phone="6709 1233 / 6747 0705",
            website="www.websoft.sg",
            uen="199802145E",
            gst_registration_no="199802145E",
        )
        # A second entity, so multi-company is demonstrable rather than
        # just anticipated: its own logo, its own module mix, its own
        # groups, staff, customers and contracts.
        company2 = Company(
            name="Websoft Digital Pte Ltd",
            logo=logo_data_uri("WD", bg="#1a1315"),
            address="2 Demo Street, #02-02, Singapore 000002",
            gst_registration_no="M9-0000002-3",
        )
        db.add_all([company, company2])
        db.flush()

        seed_module_catalog(db)
        seed_company_modules(db, company)
        # The second entity doesn't run service contracts/job orders --
        # a different module mix, controlled per company.
        seed_company_modules(
            db,
            company2,
            disabled_keys={"service_contracts", "service_operations", "service_records"},
        )
        seed_tax_codes(db, company)
        seed_tax_codes(db, company2)
        seed_chart_of_accounts(db, company)
        seed_chart_of_accounts(db, company2)
        seed_setup_lists(db)
        seed_announcements(db)
        seed_gl_types(db, company)
        seed_gl_types(db, company2)
        cash_account = (
            db.query(Account).filter(Account.company_id == company.id, Account.code == "1000").first()
        )
        seed_treasury(db, company, cash_account)
        groups = seed_groups(db, company)
        groups2 = seed_groups(db, company2)

        dennis = User(
            company_id=company.id, email="dennis@websoft.local",
            hashed_password=hash_password(DEMO_PASSWORD), must_change_password=False, full_name="Dennis (Owner)",
            role=UserRole.OWNER,
        )
        nico = User(
            company_id=company.id, email="nico@websoft.local",
            hashed_password=hash_password(DEMO_PASSWORD), must_change_password=False, full_name="Nico (Service & Support Lead)",
            role=UserRole.SERVICE_LEAD, photo=avatar_photo_data_uri("7a1f2b"),
        )
        cherish = User(
            company_id=company.id, email="cherish@websoft.local",
            hashed_password=hash_password(DEMO_PASSWORD), must_change_password=False, full_name="Cherish (Sales Manager)",
            role=UserRole.SALES_MANAGER, photo=avatar_photo_data_uri("2f4858"),
        )
        engineer = User(
            company_id=company.id, email="weiling@websoft.local",
            hashed_password=hash_password(DEMO_PASSWORD), must_change_password=False, full_name="Wei Ling (Support Engineer)",
            role=UserRole.SUPPORT_ENGINEER, photo=avatar_photo_data_uri("1b998b"),
        )
        # Confirmed 2026-09-11: 5 more Company-1 staff, purely so
        # Support Monitoring has a realistic 8-person view to demo/
        # screenshot -- same roles/pattern as the original 3, no new
        # business rule.
        marcus = User(
            company_id=company.id, email="marcus@websoft.local",
            hashed_password=hash_password(DEMO_PASSWORD), must_change_password=False, full_name="Marcus Tan (Support Engineer)",
            role=UserRole.SUPPORT_ENGINEER, photo=avatar_photo_data_uri("5b4b8a"),
        )
        farhana = User(
            company_id=company.id, email="farhana@websoft.local",
            hashed_password=hash_password(DEMO_PASSWORD), must_change_password=False, full_name="Farhana Ismail (Support Engineer)",
            role=UserRole.SUPPORT_ENGINEER, photo=avatar_photo_data_uri("c96a2c"),
        )
        kevin = User(
            company_id=company.id, email="kevin@websoft.local",
            hashed_password=hash_password(DEMO_PASSWORD), must_change_password=False, full_name="Kevin Lim (Sales Executive)",
            role=UserRole.SALES_MANAGER, photo=avatar_photo_data_uri("3a6b35"),
        )
        siti = User(
            company_id=company.id, email="siti@websoft.local",
            hashed_password=hash_password(DEMO_PASSWORD), must_change_password=False, full_name="Siti Rahman (Support Engineer)",
            role=UserRole.SUPPORT_ENGINEER, photo=avatar_photo_data_uri("8a4f7d"),
        )
        bryan = User(
            company_id=company.id, email="bryan@websoft.local",
            hashed_password=hash_password(DEMO_PASSWORD), must_change_password=False, full_name="Bryan Ong (Finance)",
            role=UserRole.FINANCE, photo=avatar_photo_data_uri("44576d"),
        )
        # Staff of the second entity only -- proves staff, groups and
        # data are company-scoped: Priya never sees company 1's records.
        priya = User(
            company_id=company2.id, email="priya@websoft.local",
            hashed_password=hash_password(DEMO_PASSWORD), must_change_password=False, full_name="Priya (Digital Lead)",
            role=UserRole.SALES_MANAGER,
        )
        db.add_all([dennis, nico, cherish, engineer, marcus, farhana, kevin, siti, bryan, priya])
        db.flush()

        # Multi-company access + the Group each person holds IN EACH
        # COMPANY (confirmed 2026-09-10: a Group per company, since
        # Groups are themselves company-scoped). Dennis works across both
        # entities and gets the company switcher -- note he is Owner /
        # Admin in company 1 but only Finance Team in company 2, which is
        # exactly what per-company groups make possible. Everyone else is
        # single-company. (The owner role can reach any company and
        # bypasses Group Authority regardless; the rows make the intent
        # explicit in the data.)
        db.add_all(
            [
                UserCompanyAccess(
                    user_id=dennis.id, company_id=company.id,
                    group_id=groups["Owner / Admin"].id,
                ),
                UserCompanyAccess(
                    user_id=dennis.id, company_id=company2.id,
                    group_id=groups2["Finance Team"].id,
                ),
                UserCompanyAccess(
                    user_id=nico.id, company_id=company.id,
                    group_id=groups["Service Team"].id,
                ),
                UserCompanyAccess(
                    user_id=cherish.id, company_id=company.id,
                    group_id=groups["Sales Team"].id,
                ),
                UserCompanyAccess(
                    user_id=engineer.id, company_id=company.id,
                    group_id=groups["Service Team"].id,
                ),
                UserCompanyAccess(
                    user_id=marcus.id, company_id=company.id,
                    group_id=groups["Service Team"].id,
                ),
                UserCompanyAccess(
                    user_id=farhana.id, company_id=company.id,
                    group_id=groups["Service Team"].id,
                ),
                UserCompanyAccess(
                    user_id=kevin.id, company_id=company.id,
                    group_id=groups["Sales Team"].id,
                ),
                UserCompanyAccess(
                    user_id=siti.id, company_id=company.id,
                    group_id=groups["Service Team"].id,
                ),
                UserCompanyAccess(
                    user_id=bryan.id, company_id=company.id,
                    group_id=groups["Finance Team"].id,
                ),
                UserCompanyAccess(
                    user_id=priya.id, company_id=company2.id,
                    group_id=groups2["Sales Team"].id,
                ),
            ]
        )

        # A demo group of companies -- Acme Manufacturing and Acme
        # Logistics are both tagged into it, showing how "search for a
        # particular customer or a group of customers" works when a
        # group actually has more than one member.
        acme_group = CompanyIndividualGroup(
            company_id=company.id, name="Acme Holdings Group",
            description="Acme Manufacturing and its related entities.",
        )
        db.add(acme_group)
        db.flush()

        customer = CompanyIndividual(
            company_id=company.id, name="Acme Manufacturing Pte Ltd",
            customer_type=CompanyIndividualType.company,
            customer_group_id=acme_group.id,
            legacy_customer_code="100CASE01",  # carried over from Odoo
            contact_person="Mr Tan Wei Ming",
            uen="201012345A",
            gst_registration_no="M2-1234567-8",
            billing_email="accounts@acme-mfg.test",
            phone="6555 1010", mobile="9123 4567",
            address_line1="10 Factory Road", address_city="Singapore",
            address_postal_code="100010", address_country="Singapore",
            memo="Long-standing customer since 2019; prefers email over phone.",
            billing_notes="Requires PO number on every invoice.",
            payment_terms_days=30,  # terms vary per customer (confirmed)
            industry_code="MFG",
        )
        # More company-1 customers, so the CompanyIndividual list/filter has
        # enough rows to be worth demoing on screen.
        acme_logistics = CompanyIndividual(
            company_id=company.id, name="Acme Logistics Pte Ltd",
            customer_type=CompanyIndividualType.company,
            customer_group_id=acme_group.id,  # same group as Acme Manufacturing
            uen="201012346B",
            contact_person="Mr Koh Boon Huat",
            billing_email="ap@acme-logistics.test",
            phone="6555 1030",
            address_line1="12 Factory Road", address_city="Singapore",
            address_postal_code="100012", address_country="Singapore",
            payment_terms_days=30,
            industry_code="LOGISTICS",
        )
        beacon = CompanyIndividual(
            company_id=company.id, name="Beacon Software Solutions Pte Ltd",
            customer_type=CompanyIndividualType.company,
            uen="201567890C",
            contact_person="Ms Chloe Ng",
            billing_email="finance@beacon-software.test",
            phone="6555 3030", mobile="9555 3031",
            address_line1="7 Ayer Rajah Crescent", address_city="Singapore",
            address_postal_code="139951", address_country="Singapore",
            payment_terms_days=45,
            industry_code="TECH",
        )
        crestview = CompanyIndividual(
            company_id=company.id, name="Crestview Engineering Pte Ltd",
            customer_type=CompanyIndividualType.company,
            uen="201245678D",
            contact_person="Mr Rajesh Kumar",
            billing_email="ap@crestview-eng.test",
            phone="6555 4040",
            address_line1="55 Ubi Avenue 3", address_city="Singapore",
            address_postal_code="408864", address_country="Singapore",
            payment_terms_days=None,  # terms not agreed yet
            industry_code="ENG",
        )
        tan_ah_kow = CompanyIndividual(
            company_id=company.id, name="Tan Ah Kow",
            customer_type=CompanyIndividualType.individual,
            billing_email="tanahkow@example.test",
            mobile="9111 2233",
            address_line1="Blk 123 Bishan St 12", address_city="Singapore",
            address_postal_code="570123", address_country="Singapore",
            payment_terms_days=7,
            # Individuals often just don't have one -- left unset on
            # purpose to demo that the field is optional.
        )
        # Company 2's own customer -- switching companies swaps the whole
        # dataset, so this is what Dennis sees under Websoft Digital.
        customer2 = CompanyIndividual(
            company_id=company2.id, name="Northwind Retail Pte Ltd",
            customer_type=CompanyIndividualType.company,
            contact_person="Ms Lim Hui Fen",
            billing_email="ap@northwind-retail.test",
            phone="6555 2020",
            address_line1="20 Orchard Lane", address_city="Singapore",
            address_postal_code="200020", address_country="Singapore",
            payment_terms_days=14,  # a different customer, different terms
            industry_code="RETAIL",
        )
        db.add_all([customer, acme_logistics, beacon, crestview, tan_ah_kow, customer2])
        db.flush()

        db.add_all(
            [
                Contact(
                    customer_id=customer.id, name="Mr Tan Wei Ming", email="wm.tan@acme-mfg.test",
                    phone="9123 4567", direct_line="6555 1011",
                ),
                Contact(customer_id=customer.id, name="Ms Farah Aziz", email="farah.aziz@acme-mfg.test", phone="9876 5432"),
                Contact(customer_id=customer2.id, name="Ms Lim Hui Fen", email="hf.lim@northwind-retail.test", phone="9234 5678"),
            ]
        )
        db.add(
            Branch(
                customer_id=customer.id, branch_name="Jurong Branch", branch_code="JB-01",
                address_line1="88 Jurong Ave", address_city="Singapore",
                address_postal_code="600088", address_country="Singapore",
                phone="6555 1088",
            )
        )

        # Product/Service Catalog (confirmed 2026-09-10 from the Odoo
        # Products screens) -- a handful of representative items so
        # Sales Quotation has something to pick from.
        catalog = {
            p.name: p
            for p in (
                Product(
                    company_id=company.id, product_type=ProductType.service,
                    name="Service / Support Contract", internal_reference="SVC-HRS",
                    product_category="Service Contracts", sales_price_sgd=Decimal("140.00"),
                    unit_of_measure="Hours", tax_code="SR",
                ),
                Product(
                    company_id=company.id, product_type=ProductType.service,
                    name="Annual Software Maintenance Contract", internal_reference="SVC-ASM",
                    product_category="In-house Software Subscription & Maintenance",
                    sales_price_sgd=Decimal("1400.00"), unit_of_measure="Yearly", tax_code="SR",
                ),
                Product(
                    company_id=company.id, product_type=ProductType.service,
                    name="API Monthly Hosting Fee", internal_reference="SVC-API",
                    product_category="In-house Software Subscription & Maintenance",
                    sales_price_sgd=Decimal("4000.00"), unit_of_measure="Monthly", tax_code="SR",
                ),
                Product(
                    company_id=company.id, product_type=ProductType.product,
                    name="Domain / DNS Hosting & Subscription", internal_reference="PRD-DNS",
                    product_category="Subscriptions", sales_price_sgd=Decimal("75.00"),
                    unit_of_measure="Yearly", tax_code="SR",
                ),
            )
        }
        db.add_all(catalog.values())
        db.flush()

        contract = contract_svc.create_contract(
            db, company_id=company.id, customer_id=customer.id,
            contracted_hours=10, contract_value_sgd=3000,
            start_date=date.today() - timedelta(days=60),
            actor_user_id=dennis.id,
            sales_staff_id=cherish.id,
            product_ids=[catalog["Service / Support Contract"].id],
        )
        contract_svc.activate_contract(db, contract, actor_user_id=dennis.id)
        billing_svc.issue_contract_annual_invoice(db, contract, actor_user_id=dennis.id)

        # A handful more contracts across the other customers so the
        # Contracts list has enough rows to demo filtering/paging on
        # screen (confirmed 2026-09-11: "viewing of at least 5
        # contracts") -- one of each remaining Contract Type, each with
        # its own sales staff and product coverage.
        annual_contract = contract_svc.create_contract(
            db, company_id=company.id, customer_id=acme_logistics.id,
            contract_kind=contract_svc.ContractKind.ANNUAL,
            contracted_hours=0, contract_value_sgd=1400,
            start_date=date.today() - timedelta(days=20),
            actor_user_id=dennis.id,
            sales_staff_id=cherish.id,
            product_ids=[catalog["Annual Software Maintenance Contract"].id],
        )
        contract_svc.activate_contract(db, annual_contract, actor_user_id=dennis.id)
        billing_svc.issue_contract_annual_invoice(db, annual_contract, actor_user_id=dennis.id)

        adhoc_contract = contract_svc.create_contract(
            db, company_id=company.id, customer_id=beacon.id,
            contract_kind=contract_svc.ContractKind.AD_HOC,
            contracted_hours=0, contract_value_sgd=0,
            hourly_rate_sgd=Decimal("160.00"),
            start_date=date.today() - timedelta(days=10),
            actor_user_id=dennis.id,
            sales_staff_id=cherish.id,
            product_ids=[catalog["API Monthly Hosting Fee"].id],
        )
        contract_svc.activate_contract(db, adhoc_contract, actor_user_id=dennis.id)

        crestview_contract = contract_svc.create_contract(
            db, company_id=company.id, customer_id=crestview.id,
            contracted_hours=15, contract_value_sgd=3600,
            start_date=date.today() - timedelta(days=200),
            actor_user_id=dennis.id,
            sales_staff_id=cherish.id,
            product_ids=[catalog["Service / Support Contract"].id],
        )
        contract_svc.activate_contract(db, crestview_contract, actor_user_id=dennis.id)
        billing_svc.issue_contract_annual_invoice(db, crestview_contract, actor_user_id=dennis.id)

        tan_contract = contract_svc.create_contract(
            db, company_id=company.id, customer_id=tan_ah_kow.id,
            contract_kind=contract_svc.ContractKind.AD_HOC,
            contracted_hours=0, contract_value_sgd=0,
            hourly_rate_sgd=Decimal("120.00"),
            start_date=date.today() - timedelta(days=5),
            actor_user_id=dennis.id,
            product_ids=[catalog["Domain / DNS Hosting & Subscription"].id],
        )
        # Left in Draft on purpose -- not every contract shown in the
        # demo should already be active.

        # A demo quotation for Acme, left "sent" (not yet accepted) so
        # the Accept -> auto-convert-to-Contract behaviour can be shown
        # live in the demo, same pattern as the pending Service Record
        # below. Its hourly line (20 hrs) clears the Contract minimum
        # (SRV-002/012) so acceptance will successfully auto-convert.
        quotation = Quotation(
            company_id=company.id, customer_id=customer.id,
            quotation_number=next_document_number(db, company_id=company.id, doc_kind="quotation"),
            quotation_date=date.today(), valid_until=date.today() + timedelta(days=30),
            notes="Renewal support block + a year of DNS hosting.",
            created_by_user_id=dennis.id,
        )
        db.add(quotation)
        db.flush()
        support = catalog["Service / Support Contract"]
        dns = catalog["Domain / DNS Hosting & Subscription"]
        db.add_all(
            [
                QuotationLine(
                    quotation_id=quotation.id, product_id=support.id, description=support.name,
                    unit_of_measure=support.unit_of_measure, quantity=Decimal("20"),
                    unit_price_sgd=support.sales_price_sgd,
                    line_total_sgd=(Decimal("20") * support.sales_price_sgd).quantize(Decimal("0.01")),
                ),
                QuotationLine(
                    quotation_id=quotation.id, product_id=dns.id, description=dns.name,
                    unit_of_measure=dns.unit_of_measure, quantity=Decimal("1"),
                    unit_price_sgd=dns.sales_price_sgd, line_total_sgd=dns.sales_price_sgd,
                ),
            ]
        )
        db.flush()
        db.refresh(quotation)
        quotation_svc.recompute_totals(db, quotation)
        quotation.status = QuotationStatus.sent

        job_order = JobOrder(
            company_id=company.id, customer_id=customer.id, contract_id=contract.id,
            job_order_number=next_document_number(db, company_id=company.id, doc_kind="job_order"),
            subject="Intermittent VPN connectivity for remote staff",
            priority=JobOrderPriority.HIGH, status=JobOrderStatus.ASSIGNED,
            assigned_to_user_id=engineer.id,
            due_date=date.today() - timedelta(days=1),  # overdue, for Support Monitoring demo
        )
        job_order2 = JobOrder(
            company_id=company.id, customer_id=customer.id, contract_id=contract.id,
            job_order_number=next_document_number(db, company_id=company.id, doc_kind="job_order"),
            subject="Set up new staff laptop",
            priority=JobOrderPriority.NORMAL, status=JobOrderStatus.ASSIGNED,
            assigned_to_user_id=engineer.id,
            due_date=date.today() + timedelta(days=1),  # due soon
        )
        job_order3 = JobOrder(
            company_id=company.id, customer_id=customer.id, contract_id=None,
            job_order_number=next_document_number(db, company_id=company.id, doc_kind="job_order"),
            subject="Investigate slow email delivery",
            priority=JobOrderPriority.LOW, status=JobOrderStatus.OPEN,
        )
        db.add_all([job_order, job_order2, job_order3])
        db.flush()

        db.add_all(
            [
                SoftwareTask(
                    company_id=company.id, title="Fix aging report rounding",
                    description="AR aging shows SGD 0.01 off on partially-allocated invoices.",
                    modules_affected="Accounts Receivable, Invoices",
                    assigned_programmer_id=dennis.id,
                    programming_finish_date=date.today() + timedelta(days=3),
                    programming_hours=Decimal("4.5"),
                    tester_user_id=cherish.id,
                    created_by_user_id=dennis.id,
                ),
                SoftwareTask(
                    company_id=company.id, title="Add branch code to invoice PDF",
                    modules_affected="Billing",
                    assigned_programmer_id=dennis.id,
                    tester_user_id=nico.id,
                    created_by_user_id=dennis.id,
                ),
            ]
        )

        # Ops Dashboard sample data (confirmed 2026-09-11) -- a couple of
        # freeform categories/tasks per staff member, themed to this
        # demo's own customers rather than copying the unrelated
        # personal-tool content from the reference screenshot. All
        # flagged is_sample=True (no in-app "reset seed" exists; these
        # are just believable starting content, like every other
        # module's demo data).
        dennis_cat1 = OpsTaskCategory(
            company_id=company.id, owner_user_id=dennis.id,
            name="CompanyIndividual Follow-ups", cadence_label="Weekly", sort_order=0,
        )
        dennis_cat2 = OpsTaskCategory(
            company_id=company.id, owner_user_id=dennis.id,
            name="Admin & Reporting", cadence_label="Month-end", sort_order=1,
        )
        nico_cat1 = OpsTaskCategory(
            company_id=company.id, owner_user_id=nico.id,
            name="Service Follow-ups", cadence_label="Daily", sort_order=0,
        )
        db.add_all([dennis_cat1, dennis_cat2, nico_cat1])
        db.flush()

        db.add_all(
            [
                OpsTask(
                    company_id=company.id, category_id=dennis_cat1.id, owner_user_id=dennis.id,
                    title="Call Acme Manufacturing re: contract renewal",
                    status=OpsTaskStatus.IN_PROGRESS, next_action="Confirm renewal hours with Cherish",
                    owner_label="Dennis", follow_up_staff_id=cherish.id,
                    follow_up_date=date.today() + timedelta(days=5), is_sample=True,
                ),
                OpsTask(
                    company_id=company.id, category_id=dennis_cat1.id, owner_user_id=dennis.id,
                    title="Review Crestview Engineering's payment terms",
                    status=OpsTaskStatus.NOT_STARTED, owner_label="Dennis", is_sample=True,
                ),
                OpsTask(
                    company_id=company.id, category_id=dennis_cat2.id, owner_user_id=dennis.id,
                    title="Prepare monthly billings summary",
                    status=OpsTaskStatus.NOT_STARTED, next_action="Export from Accounting Reports",
                    owner_label="Dennis", due_label="Month-end", is_sample=True,
                ),
                OpsTask(
                    company_id=company.id, category_id=dennis_cat2.id, owner_user_id=dennis.id,
                    title="Reconcile bank statement",
                    status=OpsTaskStatus.BLOCKED, next_action="Waiting on bank statement download",
                    owner_label="Dennis", is_sample=True,
                ),
                OpsTask(
                    company_id=company.id, category_id=nico_cat1.id, owner_user_id=nico.id,
                    title="Review pending excess usage",
                    status=OpsTaskStatus.WATCH, next_action="Check Excess Review queue",
                    owner_label="Nico", is_sample=True,
                ),
                OpsTask(
                    company_id=company.id, category_id=nico_cat1.id, owner_user_id=nico.id,
                    title="Check overdue job orders",
                    status=OpsTaskStatus.IN_PROGRESS, owner_label="Nico", is_sample=True,
                ),
            ]
        )

        # Already-approved work totalling 540 of the 600 contracted minutes.
        # Left UNCOMPLETED (the default) so the job order doesn't
        # auto-close before the final_record demo below.
        for i, raw_minutes in enumerate([240, 300]):
            record = sr_svc.submit_service_record(
                db, job_order_id=job_order.id, employee_user_id=engineer.id,
                work_date=date.today() - timedelta(days=10 - i * 3), raw_minutes=raw_minutes,
            )
            sr_svc.approve_service_record(
                db, record, job_order, approver=nico, deducted_minutes=record.rounded_minutes,
            )

        db.commit()
        print(f"Consumed so far: {contract.consumed_minutes} / {contract.contracted_minutes} minutes")

        # Final record left SUBMITTED (not approved) on purpose -- 80 raw
        # minutes rounds to 90 (SRV-007), only 60 remain, so approving this
        # live in the demo will exhaust the contract AND create an Excess
        # Usage record of 30 minutes for Nico to review on camera.
        final_record = sr_svc.submit_service_record(
            db, job_order_id=job_order.id, employee_user_id=engineer.id,
            work_date=date.today(), raw_minutes=80,
        )
        db.commit()

        # --- Accounts Payable demo: a supplier, a PO, a matched bill,
        # and a payment voucher settling it -- proves the 2-way match
        # (PUR-002) auto-approves for payment (PUR-003) end to end.
        # 2026-09-12: a supplier is a CompanyIndividual (Company/Individual)
        # record flagged is_supplier=True, not a separate master.
        supplier = CompanyIndividual(
            company_id=company.id, name="CloudHost Infrastructure Pte Ltd",
            customer_type=CompanyIndividualType.company, is_customer=False, is_supplier=True,
            billing_email="billing@cloudhost.test", phone="+65 6100 2200", payment_terms_days=30,
        )
        db.add(supplier)
        db.flush()

        po_net = Decimal("1200.00")
        _code, _rate, po_gst, po_total = apply_gst(db, company_id=company.id, net_amount=po_net)
        po = PurchaseOrder(
            company_id=company.id, supplier_id=supplier.id,
            po_number=next_document_number(db, company_id=company.id, doc_kind="purchase_order"),
            order_date=date.today() - timedelta(days=14),
            description="Annual cloud hosting renewal",
            amount_sgd=po_net, gst_amount_sgd=po_gst, total_amount_sgd=po_total,
            # No threshold is set yet (open item 4.4), so PUR-001 sends
            # every PO to the owner -- shown here rather than skipped.
            status=PurchaseOrderStatus.PENDING_APPROVAL,
        )
        db.add(po)
        db.flush()
        ap_svc.approve_purchase_order(db, po, actor=dennis)

        bill = SupplierInvoice(
            company_id=company.id, supplier_id=supplier.id, purchase_order_id=po.id,
            bill_number=next_document_number(db, company_id=company.id, doc_kind="supplier_invoice"),
            supplier_invoice_no="CH-2026-4471",
            invoice_date=date.today() - timedelta(days=2),
            due_date=ap_svc.due_date_for_bill(db, supplier.id, date.today() - timedelta(days=2)),
            description="Annual cloud hosting renewal",
            amount_sgd=po_net, gst_amount_sgd=po_gst, total_amount_sgd=po_total,
        )
        db.add(bill)
        db.flush()
        ap_svc.match_bill_to_po(db, bill)  # PUR-002 match -> PUR-003 auto-approves

        payment_voucher = SupplierPayment(
            company_id=company.id, supplier_id=supplier.id,
            voucher_number=next_document_number(db, company_id=company.id, doc_kind="payment"),
            payment_date=date.today(), amount_sgd=po_total,
            method="bank_transfer", reference="DBS-TT-55231",
            paid_by_user_id=dennis.id,
        )
        db.add(payment_voucher)
        db.flush()
        ap_svc.allocate_supplier_payment(db, payment_voucher, bill, po_total)

        # A second PO, approved but not yet imported -- so "Import to AP"
        # (2026-09-12) has something to demo without unwinding the fully
        # paid example above.
        po2_net = Decimal("340.00")
        _code, _rate, po2_gst, po2_total = apply_gst(db, company_id=company.id, net_amount=po2_net)
        po2 = PurchaseOrder(
            company_id=company.id, supplier_id=supplier.id,
            po_number=next_document_number(db, company_id=company.id, doc_kind="purchase_order"),
            order_date=date.today() - timedelta(days=1),
            description="Extra storage add-on",
            amount_sgd=po2_net, gst_amount_sgd=po2_gst, total_amount_sgd=po2_total,
            status=PurchaseOrderStatus.PENDING_APPROVAL,
        )
        db.add(po2)
        db.flush()
        ap_svc.approve_purchase_order(db, po2, actor=dennis)
        db.commit()

        print("\n=== Demo dataset ready ===")
        print(f"Company 1: {company.name} (logo set)")
        print(f"Company 2: {company2.name} (logo set) -- customer: {customer2.name}")
        print(f"Company/Individual: {customer.name} ({customer.id})")
        print(f"Contract: {contract.id} -- {contract.consumed_minutes}/{contract.contracted_minutes} min consumed")
        print(f"Job order: {job_order.id} -- {job_order.subject}")
        print(f"Pending service record (approve live in demo): {final_record.id} -- {final_record.raw_minutes} raw min -> {final_record.rounded_minutes} rounded min")
        print("\nEnabled modules:", ", ".join(k for k, _, _, e in MODULE_CATALOG if e))
        print("\nGroups:", ", ".join(GROUP_CATALOG.keys()))
        print("\nLogins (all password: demo1234):")
        all_groups = {**{g.id: n for n, g in groups.items()}, **{g.id: n for n, g in groups2.items()}}
        company_names = {company.id: company.name, company2.id: company2.name}
        access_rows = db.query(UserCompanyAccess).all()
        for u in (dennis, nico, cherish, engineer, marcus, farhana, kevin, siti, bryan, priya):
            print(f"  {u.email:30s} role={u.role.value}")
            for row in [a for a in access_rows if a.user_id == u.id]:
                marker = " (active)" if row.company_id == u.company_id else ""
                print(
                    f"      {company_names[row.company_id]:32s} "
                    f"group={all_groups.get(row.group_id, '-')}{marker}"
                )
        print(
            "\nDennis has access to both companies -- the switcher appears for him only, and he "
            "holds a different Group in each (Owner / Admin vs Finance Team)."
        )
    except Exception:
        db.rollback()
        raise
    finally:
        db.close()


if __name__ == "__main__":
    main()
