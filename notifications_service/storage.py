"""SQLite-backed persistence for subscriptions and toasts."""
from __future__ import annotations

import json
import sqlite3
from datetime import datetime
from pathlib import Path
from typing import Any, Dict, List


class NotificationStore:
    """Storage helper around sqlite3."""

    def __init__(self, db_path: Path) -> None:
        self.db_path = Path(db_path)
        self._ensure_tables()

    def _get_conn(self) -> sqlite3.Connection:
        conn = sqlite3.connect(self.db_path)
        conn.row_factory = sqlite3.Row
        return conn

    def _ensure_tables(self) -> None:
        conn = self._get_conn()
        cur = conn.cursor()
        cur.execute(
            """
            CREATE TABLE IF NOT EXISTS push_subscriptions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT NOT NULL,
                endpoint TEXT NOT NULL UNIQUE,
                keys_json TEXT NOT NULL,
                subscription_json TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
            """
        )
        cur.execute(
            """
            CREATE TABLE IF NOT EXISTS toast_notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT NOT NULL,
                message TEXT NOT NULL,
                variant TEXT NOT NULL,
                context_json TEXT,
                created_at TEXT NOT NULL
            )
            """
        )
        conn.commit()
        conn.close()

    @staticmethod
    def _now() -> str:
        return datetime.utcnow().isoformat(timespec="seconds")

    def save_subscription(self, user_id: str, subscription: Dict[str, Any]) -> None:
        endpoint = subscription.get("endpoint")
        if not endpoint:
            raise ValueError("subscription endpoint missing")
        keys_json = json.dumps(subscription.get("keys", {}))
        payload_json = json.dumps(subscription)
        conn = self._get_conn()
        cur = conn.cursor()
        cur.execute(
            """
            INSERT INTO push_subscriptions (user_id, endpoint, keys_json, subscription_json, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT(endpoint) DO UPDATE SET
                user_id=excluded.user_id,
                keys_json=excluded.keys_json,
                subscription_json=excluded.subscription_json,
                updated_at=excluded.updated_at
            """,
            (user_id, endpoint, keys_json, payload_json, self._now(), self._now()),
        )
        conn.commit()
        conn.close()

    def remove_subscription(self, endpoint: str) -> None:
        conn = self._get_conn()
        conn.execute("DELETE FROM push_subscriptions WHERE endpoint = ?", (endpoint,))
        conn.commit()
        conn.close()

    def subscriptions_for_user(self, user_id: str) -> List[Dict[str, Any]]:
        conn = self._get_conn()
        cur = conn.cursor()
        cur.execute(
            "SELECT endpoint, subscription_json FROM push_subscriptions WHERE user_id = ?",
            (user_id,),
        )
        rows = cur.fetchall()
        conn.close()
        out: List[Dict[str, Any]] = []
        for row in rows:
            try:
                payload = json.loads(row["subscription_json"])
            except Exception:
                payload = {"endpoint": row["endpoint"]}
            out.append(payload)
        return out

    def add_toast(self, user_id: str, message: str, variant: str, context: Dict[str, Any]) -> int:
        conn = self._get_conn()
        cur = conn.cursor()
        cur.execute(
            """
            INSERT INTO toast_notifications (user_id, message, variant, context_json, created_at)
            VALUES (?, ?, ?, ?, ?)
            """,
            (user_id, message, variant, json.dumps(context), self._now()),
        )
        conn.commit()
        toast_id = cur.lastrowid
        conn.close()
        return int(toast_id)

    def pull_toasts(self, user_id: str) -> List[Dict[str, Any]]:
        conn = self._get_conn()
        cur = conn.cursor()
        cur.execute(
            "SELECT id, message, variant, context_json, created_at FROM toast_notifications WHERE user_id = ? ORDER BY id ASC",
            (user_id,),
        )
        rows = cur.fetchall()
        if rows:
            ids = [str(row["id"]) for row in rows]
            cur.execute(
                f"DELETE FROM toast_notifications WHERE id IN ({','.join('?' for _ in ids)})",
                ids,
            )
            conn.commit()
        conn.close()
        out: List[Dict[str, Any]] = []
        for row in rows:
            try:
                context = json.loads(row["context_json"] or "{}")
            except Exception:
                context = {}
            out.append(
                {
                    "id": row["id"],
                    "message": row["message"],
                    "type": row["variant"],
                    "context": context,
                    "created_at": row["created_at"],
                }
            )
        return out
