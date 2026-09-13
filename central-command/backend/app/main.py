"""
Web Master Central Command — API entrypoint.

A separate application from the client ERP. Manages all deployed
ERP instances from one place: pushes ads/banners, controls module
licenses, and distributes config updates to client databases.
"""
from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from app.core.config import settings
from app.routers import advertisements, auth, clients, config_updates, dashboard, licenses, versions, staff

app = FastAPI(title=settings.app_name)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["http://localhost:5174"],  # CC frontend dev server
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

app.include_router(auth.router)
app.include_router(dashboard.router)
app.include_router(clients.router)
app.include_router(advertisements.router)
app.include_router(licenses.router)
app.include_router(config_updates.router)
app.include_router(versions.router)
app.include_router(staff.router)


@app.get("/api/health")
def health() -> dict:
    return {"status": "ok", "app": settings.app_name}
