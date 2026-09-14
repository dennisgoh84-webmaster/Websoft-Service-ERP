"""
Websoft Service ERP Solution -- API entrypoint.

Scope for this build: the Service Operations core slice (Company/Individual,
Service Contract, Job Order, Service Record, Excess Usage Review,
Billing/Invoice) implementing the confirmed SRV-001..018 business rules,
plus Module Control / multi-company licensing and a summary dashboard.
Commission Management, further Service Record business-rule decisions,
and Odoo migration work are deferred for now at the user's request.
See ../../docs/business-requirements.md for the source of truth on rules.
"""
from fastapi import FastAPI, Request
from fastapi.middleware.cors import CORSMiddleware

from app.core.config import settings
from app.routers import (
    accounts,
    announcements,
    approvals,
    bank_accounts,
    bank_transactions,
    payables,
    catalog,
    commissions,
    currency_rates,
    document_control,
    documents,
    gl_types,
    ledger,
    accounts_receivable,
    auth,
    billing,
    companies,
    company_individual_groups,
    company_individuals,
    contracts,
    dashboard,
    event_logs,
    excess_usage,
    groups,
    incidents,
    job_orders,
    mobile,
    modules,
    monitoring,
    ops_dashboard,
    periods,
    portal,
    quotations,
    reference_codes,
    reports,
    service_records,
    setup_lists,
    software_tasks,
    stock,
    tax_codes,
    users,
)
from app.services import audit

app = FastAPI(title=settings.app_name)

# Demo-only CORS: the React dev server runs on a different port.
app.add_middleware(
    CORSMiddleware,
    allow_origins=["http://localhost:5173"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


@app.middleware("http")
async def audit_request_context_middleware(request: Request, call_next):
    """Captures who/what made this request (client IP, browser User-Agent,
    and the frontend's persisted per-browser device id -- see
    frontend/src/lib/deviceId.ts) so app.services.audit.record() can
    stamp every audit entry written during this request without every
    caller having to pass a Request through. See app/services/audit.py."""
    audit.set_request_context(
        ip_address=request.client.host if request.client else None,
        user_agent=request.headers.get("user-agent"),
        device_id=request.headers.get("x-device-id"),
    )
    return await call_next(request)


app.include_router(auth.router)
app.include_router(users.router)
app.include_router(companies.router)
app.include_router(announcements.router)
app.include_router(groups.router)
app.include_router(modules.router)
app.include_router(event_logs.router)
app.include_router(company_individuals.router)
app.include_router(company_individual_groups.router)
app.include_router(catalog.router)
app.include_router(quotations.router)
app.include_router(contracts.router)
app.include_router(job_orders.router)
app.include_router(service_records.router)
app.include_router(excess_usage.router)
app.include_router(incidents.router)
app.include_router(billing.router)
app.include_router(accounts_receivable.router)
app.include_router(accounts.router)
app.include_router(reference_codes.router)
app.include_router(ledger.router)
app.include_router(payables.router)
app.include_router(dashboard.router)
app.include_router(monitoring.router)
app.include_router(software_tasks.router)
app.include_router(reports.router)
app.include_router(setup_lists.router)
app.include_router(gl_types.router)
app.include_router(currency_rates.router)
app.include_router(bank_accounts.router)
app.include_router(bank_transactions.router)
app.include_router(document_control.router)
app.include_router(tax_codes.router)
app.include_router(periods.router)
app.include_router(ops_dashboard.router)
app.include_router(portal.router)
app.include_router(mobile.router)
app.include_router(documents.router)
app.include_router(approvals.router)
app.include_router(commissions.router)
app.include_router(stock.router)


@app.get("/api/health")
def health() -> dict:
    return {"status": "ok", "app": settings.app_name}
