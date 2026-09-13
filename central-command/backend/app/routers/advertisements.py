"""Advertisement management + push to client DBs."""
import uuid

from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.orm import Session, joinedload

from app.core.database import get_db
from app.core.deps import get_current_admin
from app.models.admin import AdminUser
from app.models.advertisements import AdAssignment, Advertisement, VideoAssignment, VideoSetting
from app.models.clients import Client
from app.schemas import AdvertisementCreate, AdvertisementOut, AdvertisementUpdate, VideoSettingCreate, VideoSettingOut
from app.services.client_db import ClientDBError, push_announcements, push_video_url

router = APIRouter(prefix="/api/advertisements", tags=["advertisements"])


# ── Announcements ─────────────────────────────────────────────────────

@router.get("/", response_model=list[AdvertisementOut])
def list_ads(db: Session = Depends(get_db), _admin: AdminUser = Depends(get_current_admin)):
    ads = (
        db.query(Advertisement)
        .options(joinedload(Advertisement.assignments))
        .order_by(Advertisement.sort_order)
        .all()
    )
    return ads


@router.post("/", response_model=AdvertisementOut, status_code=201)
def create_ad(body: AdvertisementCreate, db: Session = Depends(get_db), _admin: AdminUser = Depends(get_current_admin)):
    ad = Advertisement(tag=body.tag, text=body.text, sort_order=body.sort_order, is_active=body.is_active)
    db.add(ad)
    db.flush()
    for cid in body.client_ids:
        db.add(AdAssignment(advertisement_id=ad.id, client_id=cid))
    db.commit()
    db.refresh(ad)
    return db.query(Advertisement).options(joinedload(Advertisement.assignments)).get(ad.id)


@router.patch("/{ad_id}", response_model=AdvertisementOut)
def update_ad(ad_id: str, body: AdvertisementUpdate, db: Session = Depends(get_db), _admin: AdminUser = Depends(get_current_admin)):
    ad = db.get(Advertisement, ad_id)
    if not ad:
        raise HTTPException(404, "Advertisement not found")
    updates = body.model_dump(exclude_unset=True)
    client_ids = updates.pop("client_ids", None)
    for k, v in updates.items():
        setattr(ad, k, v)
    if client_ids is not None:
        db.query(AdAssignment).filter(AdAssignment.advertisement_id == ad.id).delete()
        for cid in client_ids:
            db.add(AdAssignment(advertisement_id=ad.id, client_id=cid))
    db.commit()
    return db.query(Advertisement).options(joinedload(Advertisement.assignments)).get(ad.id)


@router.delete("/{ad_id}", status_code=204)
def delete_ad(ad_id: str, db: Session = Depends(get_db), _admin: AdminUser = Depends(get_current_admin)):
    ad = db.get(Advertisement, ad_id)
    if not ad:
        raise HTTPException(404, "Advertisement not found")
    db.delete(ad)
    db.commit()


@router.post("/{ad_id}/push")
def push_ad(ad_id: str, db: Session = Depends(get_db), admin: AdminUser = Depends(get_current_admin)):
    """Push this advertisement to all assigned clients."""
    ad = db.query(Advertisement).options(joinedload(Advertisement.assignments)).get(ad_id)
    if not ad:
        raise HTTPException(404, "Advertisement not found")

    results = []
    for assignment in ad.assignments:
        client = db.get(Client, assignment.client_id)
        if not client:
            continue
        try:
            push_announcements(db, client, [{
                "id": str(ad.id),
                "tag": ad.tag,
                "text": ad.text,
                "sort_order": ad.sort_order,
                "is_active": ad.is_active,
            }], admin_id=admin.id)
            assignment.pushed_at = __import__("datetime").datetime.now(__import__("datetime").timezone.utc)
            results.append({"client": client.name, "success": True})
        except ClientDBError as e:
            results.append({"client": client.name, "success": False, "error": str(e)})

    db.commit()
    return {"results": results}


@router.post("/push-all")
def push_all_ads(db: Session = Depends(get_db), admin: AdminUser = Depends(get_current_admin)):
    """Push ALL active advertisements to their assigned clients."""
    ads = (
        db.query(Advertisement)
        .options(joinedload(Advertisement.assignments))
        .filter(Advertisement.is_active.is_(True))
        .all()
    )

    results = []
    clients_done: dict[uuid.UUID, list[dict]] = {}

    # Group by client
    for ad in ads:
        for assignment in ad.assignments:
            if assignment.client_id not in clients_done:
                clients_done[assignment.client_id] = []
            clients_done[assignment.client_id].append({
                "id": str(ad.id),
                "tag": ad.tag,
                "text": ad.text,
                "sort_order": ad.sort_order,
                "is_active": ad.is_active,
            })

    for client_id, ann_list in clients_done.items():
        client = db.get(Client, client_id)
        if not client:
            continue
        try:
            push_announcements(db, client, ann_list, admin_id=admin.id)
            results.append({"client": client.name, "success": True, "count": len(ann_list)})
        except ClientDBError as e:
            results.append({"client": client.name, "success": False, "error": str(e)})

    db.commit()
    return {"results": results}


# ── Video Settings ────────────────────────────────────────────────────

@router.get("/videos", response_model=list[VideoSettingOut])
def list_videos(db: Session = Depends(get_db), _admin: AdminUser = Depends(get_current_admin)):
    return db.query(VideoSetting).order_by(VideoSetting.created_at.desc()).all()


@router.post("/videos", response_model=VideoSettingOut, status_code=201)
def create_video(body: VideoSettingCreate, db: Session = Depends(get_db), _admin: AdminUser = Depends(get_current_admin)):
    vs = VideoSetting(video_url=body.video_url, label=body.label)
    db.add(vs)
    db.flush()
    for cid in body.client_ids:
        db.add(VideoAssignment(video_setting_id=vs.id, client_id=cid))
    db.commit()
    db.refresh(vs)
    return vs


@router.post("/videos/{video_id}/push")
def push_video(video_id: str, db: Session = Depends(get_db), admin: AdminUser = Depends(get_current_admin)):
    """Push video URL to assigned clients."""
    vs = db.get(VideoSetting, video_id)
    if not vs:
        raise HTTPException(404, "Video setting not found")

    assignments = db.query(VideoAssignment).filter(VideoAssignment.video_setting_id == vs.id).all()
    results = []
    for assignment in assignments:
        client = db.get(Client, assignment.client_id)
        if not client:
            continue
        try:
            push_video_url(db, client, vs.video_url, admin_id=admin.id)
            assignment.pushed_at = __import__("datetime").datetime.now(__import__("datetime").timezone.utc)
            results.append({"client": client.name, "success": True})
        except ClientDBError as e:
            results.append({"client": client.name, "success": False, "error": str(e)})

    db.commit()
    return {"results": results}
