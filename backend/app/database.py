import sqlite3
import hashlib
import json
from pathlib import Path
from datetime import datetime
from typing import Optional

from .models import Review, Reply


def _hash(review: Review) -> str:
    payload = {
        "id": review.basalam_review_id,
        "star": review.star,
        "description": review.description,
        "replies": [r.description for r in review.replies],
    }
    return hashlib.sha256(json.dumps(payload, sort_keys=True).encode()).hexdigest()


class Database:
    def __init__(self, path: str):
        Path(path).parent.mkdir(parents=True, exist_ok=True)
        self._path = path
        self._init()

    def _connect(self) -> sqlite3.Connection:
        conn = sqlite3.connect(self._path)
        conn.row_factory = sqlite3.Row
        conn.execute("PRAGMA journal_mode=WAL")
        conn.execute("PRAGMA foreign_keys=ON")
        return conn

    def _init(self):
        with self._connect() as conn:
            conn.executescript("""
                CREATE TABLE IF NOT EXISTS reviews (
                    basalam_review_id   INTEGER PRIMARY KEY,
                    basalam_product_id  INTEGER NOT NULL,
                    product_title       TEXT,
                    vendor_id           INTEGER NOT NULL,
                    user_name           TEXT,
                    user_id             INTEGER,
                    star                INTEGER,
                    description         TEXT,
                    created_at          TEXT,
                    wc_product_id       INTEGER,
                    wc_comment_id       INTEGER,
                    synced_at           TEXT,
                    hash                TEXT
                );

                CREATE TABLE IF NOT EXISTS replies (
                    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                    basalam_review_id   INTEGER NOT NULL REFERENCES reviews(basalam_review_id),
                    basalam_answer_id   INTEGER,
                    author_name         TEXT,
                    description         TEXT,
                    wc_comment_id       INTEGER,
                    synced_at           TEXT
                );

                CREATE TABLE IF NOT EXISTS product_mappings (
                    basalam_product_id  INTEGER PRIMARY KEY,
                    wc_product_id       INTEGER NOT NULL,
                    basalam_title       TEXT,
                    wc_title            TEXT,
                    fetched_at          TEXT
                );

                CREATE TABLE IF NOT EXISTS sync_log (
                    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                    run_at              TEXT NOT NULL,
                    mode                TEXT,
                    reviews_fetched     INTEGER DEFAULT 0,
                    reviews_inserted    INTEGER DEFAULT 0,
                    reviews_skipped     INTEGER DEFAULT 0,
                    errors              INTEGER DEFAULT 0,
                    error_messages      TEXT
                );
            """)

    def upsert_review(self, review: Review) -> bool:
        """Insert or update a review. Returns True if it's new/changed."""
        h = _hash(review)
        with self._connect() as conn:
            existing = conn.execute(
                "SELECT hash FROM reviews WHERE basalam_review_id = ?",
                (review.basalam_review_id,)
            ).fetchone()

            if existing and existing["hash"] == h:
                return False

            # When an already-synced review changes (new reply), clear wc_comment_id
            # so it re-enters the unsynced queue and new replies reach the plugin.
            conn.execute("""
                INSERT INTO reviews
                    (basalam_review_id, basalam_product_id, product_title, vendor_id,
                     user_name, user_id, star, description, created_at, hash)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(basalam_review_id) DO UPDATE SET
                    star          = excluded.star,
                    description   = excluded.description,
                    hash          = excluded.hash,
                    wc_comment_id = NULL,
                    synced_at     = NULL
            """, (
                review.basalam_review_id, review.basalam_product_id,
                review.product_title, review.vendor_id, review.user_name,
                review.user_id, review.star, review.description,
                review.created_at, h,
            ))

            conn.execute(
                "DELETE FROM replies WHERE basalam_review_id = ?",
                (review.basalam_review_id,)
            )
            for reply in review.replies:
                conn.execute("""
                    INSERT INTO replies (basalam_review_id, basalam_answer_id, author_name, description)
                    VALUES (?, ?, ?, ?)
                """, (review.basalam_review_id, reply.basalam_answer_id,
                      reply.author_name, reply.description))
            return True

    def mark_synced(self, basalam_review_id: int, wc_comment_id: int):
        with self._connect() as conn:
            conn.execute("""
                UPDATE reviews SET wc_comment_id = ?, synced_at = ?
                WHERE basalam_review_id = ?
            """, (wc_comment_id, datetime.utcnow().isoformat(), basalam_review_id))

    def get_unsynced(self, limit: int = 100) -> list[Review]:
        with self._connect() as conn:
            rows = conn.execute("""
                SELECT * FROM reviews WHERE wc_comment_id IS NULL
                ORDER BY created_at ASC LIMIT ?
            """, (limit,)).fetchall()

            reviews = []
            for row in rows:
                replies_rows = conn.execute(
                    "SELECT * FROM replies WHERE basalam_review_id = ?",
                    (row["basalam_review_id"],)
                ).fetchall()
                replies = [Reply(
                    author_name=r["author_name"],
                    description=r["description"],
                    basalam_answer_id=r["basalam_answer_id"],
                    wc_comment_id=r["wc_comment_id"],
                ) for r in replies_rows]
                reviews.append(Review(
                    basalam_review_id=row["basalam_review_id"],
                    basalam_product_id=row["basalam_product_id"],
                    product_title=row["product_title"],
                    vendor_id=row["vendor_id"],
                    user_name=row["user_name"],
                    user_id=row["user_id"],
                    star=row["star"],
                    description=row["description"],
                    created_at=row["created_at"],
                    wc_product_id=row["wc_product_id"],
                    wc_comment_id=row["wc_comment_id"],
                    replies=replies,
                ))
            return reviews

    def set_wc_product_id(self, basalam_product_id: int, wc_product_id: int):
        with self._connect() as conn:
            conn.execute("""
                UPDATE reviews SET wc_product_id = ?
                WHERE basalam_product_id = ? AND wc_product_id IS NULL
            """, (wc_product_id, basalam_product_id))

    def upsert_mapping(self, basalam_id: int, wc_id: int,
                       basalam_title: str = "", wc_title: str = ""):
        with self._connect() as conn:
            conn.execute("""
                INSERT INTO product_mappings
                    (basalam_product_id, wc_product_id, basalam_title, wc_title, fetched_at)
                VALUES (?, ?, ?, ?, ?)
                ON CONFLICT(basalam_product_id) DO UPDATE SET
                    wc_product_id = excluded.wc_product_id,
                    fetched_at = excluded.fetched_at
            """, (basalam_id, wc_id, basalam_title, wc_title,
                  datetime.utcnow().isoformat()))

    def get_all_review_ids(self) -> set[int]:
        with self._connect() as conn:
            rows = conn.execute("SELECT basalam_review_id FROM reviews").fetchall()
            return {row["basalam_review_id"] for row in rows}

    def get_wc_product_id(self, basalam_product_id: int) -> Optional[int]:
        with self._connect() as conn:
            row = conn.execute(
                "SELECT wc_product_id FROM product_mappings WHERE basalam_product_id = ?",
                (basalam_product_id,)
            ).fetchone()
            return row["wc_product_id"] if row else None

    def log_sync(self, mode: str, fetched: int, inserted: int,
                 skipped: int, errors: int, error_messages: list[str]):
        with self._connect() as conn:
            conn.execute("""
                INSERT INTO sync_log
                    (run_at, mode, reviews_fetched, reviews_inserted,
                     reviews_skipped, errors, error_messages)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            """, (datetime.utcnow().isoformat(), mode, fetched, inserted,
                  skipped, errors, json.dumps(error_messages, ensure_ascii=False)))

    def stats(self) -> dict:
        with self._connect() as conn:
            total = conn.execute("SELECT COUNT(*) FROM reviews").fetchone()[0]
            synced = conn.execute(
                "SELECT COUNT(*) FROM reviews WHERE wc_comment_id IS NOT NULL"
            ).fetchone()[0]
            unsynced = total - synced
            last_run = conn.execute(
                "SELECT run_at, mode FROM sync_log ORDER BY id DESC LIMIT 1"
            ).fetchone()
            return {
                "total_reviews": total,
                "synced": synced,
                "unsynced": unsynced,
                "last_run": dict(last_run) if last_run else None,
            }
