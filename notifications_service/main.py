"""FastAPI notification service entrypoint."""
from __future__ import annotations

import json
from typing import Any, Dict, List, Optional

from fastapi import FastAPI, HTTPException, Query
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field
from pywebpush import WebPushException, webpush

from .config import settings
from .storage import NotificationStore

app = FastAPI(title="Notifications Service", version="1.0.0")
store = NotificationStore(settings.database_path)


class ToastPayload(BaseModel):
    user_id: str | int
    message: str = Field(min_length=1)
    type: str = Field(default="info", pattern="^(success|error|info|warning)$")
    context: Dict[str, Any] = Field(default_factory=dict)


class PushPayload(BaseModel):
    user_id: str | int
    title: str = Field(min_length=1)
    body: str = Field(min_length=1)
    url: Optional[str] = None
    icon: Optional[str] = None


class SubscriptionPayload(BaseModel):
    user_id: str | int
    subscription: Dict[str, Any]


class ToastPollResponse(BaseModel):
    ok: bool
    items: List[Dict[str, Any]]


@app.post("/api/notifications/toast")
def create_toast(payload: ToastPayload) -> Dict[str, Any]:
    user_id = str(payload.user_id)
    toast_id = store.add_toast(user_id, payload.message, payload.type, payload.context)
    return {"ok": True, "id": toast_id}


@app.get("/api/notifications/toast", response_model=ToastPollResponse)
def poll_toasts(user_id: str = Query(..., description="User identifier")) -> ToastPollResponse:
    items = store.pull_toasts(str(user_id))
    return ToastPollResponse(ok=True, items=items)


@app.post("/api/notifications/register-subscription")
def register_subscription(payload: SubscriptionPayload) -> Dict[str, Any]:
    try:
        store.save_subscription(str(payload.user_id), payload.subscription)
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc))
    return {"ok": True}


@app.post("/api/notifications/push")
def send_push(payload: PushPayload) -> Dict[str, Any]:
    if not settings.vapid_ready:
        raise HTTPException(status_code=500, detail="VAPID keys are not configured")

    user_id = str(payload.user_id)
    subscriptions = store.subscriptions_for_user(user_id)
    if not subscriptions:
        return {"ok": True, "sent": 0}

    delivered = 0
    failures: List[str] = []
    data = {
        "title": payload.title,
        "body": payload.body,
        "url": payload.url or "/",
        "icon": payload.icon or settings.default_icon,
    }

    for sub in subscriptions:
        try:
            webpush(
                subscription_info=sub,
                data=json.dumps(data),
                vapid_private_key=settings.vapid_private_key,
                vapid_claims={"sub": settings.vapid_email, "aud": sub.get("endpoint", "")},
                ttl=3600,
            )
            delivered += 1
        except WebPushException as exc:
            status = getattr(exc.response, "status_code", None)
            endpoint = sub.get("endpoint")
            if status in {404, 410} and endpoint:
                store.remove_subscription(endpoint)
            failures.append(str(exc))
        except Exception as exc:  # pragma: no cover - network errors
            failures.append(str(exc))

    body: Dict[str, Any] = {"ok": True, "sent": delivered}
    if failures:
        body["failed"] = len(failures)
    return JSONResponse(body)


@app.get("/healthz")
def healthcheck() -> Dict[str, Any]:
    return {"ok": True}


# Convenience comment for developers.
# Run with: uvicorn notifications_service.main:app --reload --port 8001
