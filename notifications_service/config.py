"""Configuration helpers for the notification service."""
from __future__ import annotations

import os
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent
DEFAULT_DB_PATH = BASE_DIR / "notifications.db"


class Settings:
    """Container object for runtime configuration."""

    def __init__(self) -> None:
        self.database_path = Path(os.environ.get("NOTIFICATIONS_DB_PATH", DEFAULT_DB_PATH))
        self.vapid_public_key = os.environ.get("NOTIFICATIONS_VAPID_PUBLIC_KEY", "")
        self.vapid_private_key = os.environ.get("NOTIFICATIONS_VAPID_PRIVATE_KEY", "")
        self.vapid_email = os.environ.get("NOTIFICATIONS_VAPID_EMAIL", "mailto:admin@example.com")
        self.default_icon = os.environ.get("NOTIFICATIONS_DEFAULT_ICON", "/assets/logo.png")

    @property
    def vapid_ready(self) -> bool:
        return bool(self.vapid_public_key and self.vapid_private_key)


settings = Settings()
