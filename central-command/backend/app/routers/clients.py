"""Client registry CRUD + connection testing."""
from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import get_current_admin
from app.models.admin import AdminUser
from app.models.clients import Client, ClientStatus
from app.schemas import ClientCreate, ClientOut, ClientSummary, ClientUpdate, ConnectionTestResult
from app.services.client_db import test_connection

router = APIRouter(prefix="/api/clients", tags=["clients"])


@router.get("/", response_model=list[ClientSummary])
def list_clients(db: Session = Depends(get_db), _admin: AdminUser = Depends(get_current_admin)):
    return db.query(Client).order_by(Client.name).all()


@router.get("/{client_id}", response_model=ClientOut)
def get_client(client_id: str, db: Session = Depends(get_db), _admin: AdminUser = Depends(get_current_admin)):
    client = db.get(Client, client_id)
    if not client:
        raise HTTPException(404, "Client not found")
    return client


@router.post("/", response_model=ClientOut, status_code=201)
def create_client(body: ClientCreate, db: Session = Depends(get_db), _admin: AdminUser = Depends(get_current_admin)):
    if db.query(Client).filter(Client.code == body.code).first():
        raise HTTPException(400, f"Client code '{body.code}' already exists")
    client = Client(**body.model_dump())
    db.add(client)
    db.commit()
    db.refresh(client)
    return client


@router.patch("/{client_id}", response_model=ClientOut)
def update_client(client_id: str, body: ClientUpdate, db: Session = Depends(get_db), _admin: AdminUser = Depends(get_current_admin)):
    client = db.get(Client, client_id)
    if not client:
        raise HTTPException(404, "Client not found")
    for k, v in body.model_dump(exclude_unset=True).items():
        if k == "status":
            v = ClientStatus(v)
        setattr(client, k, v)
    db.commit()
    db.refresh(client)
    return client


@router.post("/{client_id}/test-connection", response_model=ConnectionTestResult)
def test_client_connection(client_id: str, db: Session = Depends(get_db), _admin: AdminUser = Depends(get_current_admin)):
    client = db.get(Client, client_id)
    if not client:
        raise HTTPException(404, "Client not found")
    result = test_connection(client)
    if result["success"]:
        client.last_connected_at = __import__("datetime").datetime.now(__import__("datetime").timezone.utc)
        client.last_known_alembic_head = result.get("alembic_head")
        db.commit()
    return result


@router.delete("/{client_id}", status_code=204)
def delete_client(client_id: str, db: Session = Depends(get_db), _admin: AdminUser = Depends(get_current_admin)):
    client = db.get(Client, client_id)
    if not client:
        raise HTTPException(404, "Client not found")
    db.delete(client)
    db.commit()
