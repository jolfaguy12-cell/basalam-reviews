import logging
from datetime import datetime

from .config import get_settings
from .crawler import BasalamCrawler
from .database import Database
from .datahub_client import DataHubClient
from .wordpress_client import WordPressClient
from .models import SyncResult

logger = logging.getLogger(__name__)


def _resolve_wc_product_id(basalam_product_id: int, db: Database,
                            hub: DataHubClient) -> int | None:
    cached = db.get_wc_product_id(basalam_product_id)
    if cached:
        return cached
    wc_id = hub.get_wc_product_id(basalam_product_id)
    if wc_id:
        db.upsert_mapping(basalam_product_id, wc_id)
        db.set_wc_product_id(basalam_product_id, wc_id)
    return wc_id


def run_sync(mode: str = "incremental") -> SyncResult:
    cfg = get_settings()
    db = Database(cfg.internal_db_path)
    crawler = BasalamCrawler()
    hub = DataHubClient()
    wp = WordPressClient()

    result = SyncResult(run_at=datetime.utcnow().isoformat(), mode=mode)
    logger.info("Sync started mode=%s", mode)

    # ── Step 1: crawl reviews — incremental uses early-stop ───────────────────
    known_ids = db.get_all_review_ids() if mode == "incremental" else None
    new_or_changed: list = []
    for review in crawler.iter_all_reviews(known_ids=known_ids):
        result.reviews_fetched += 1
        changed = db.upsert_review(review)
        if changed:
            new_or_changed.append(review)

    logger.info("Fetched %d reviews, %d new/changed",
                result.reviews_fetched, len(new_or_changed))

    # ── Step 2: resolve product mappings via Data Hub ─────────────────────────
    for review in new_or_changed:
        wc_id = _resolve_wc_product_id(review.basalam_product_id, db, hub)
        review.wc_product_id = wc_id

    # ── Step 3: push unsynced reviews to WordPress ────────────────────────────
    unsynced = db.get_unsynced(limit=200)
    for review in unsynced:
        if not review.wc_product_id:
            wc_id = _resolve_wc_product_id(review.basalam_product_id, db, hub)
            review.wc_product_id = wc_id

        if not review.wc_product_id:
            logger.debug(
                "No WC product mapping for basalam_product_id=%d — skipping",
                review.basalam_product_id,
            )
            result.reviews_skipped += 1
            continue

        try:
            wc_comment_id = wp.push_review(review)
            if wc_comment_id:
                db.mark_synced(review.basalam_review_id, wc_comment_id)
                result.reviews_inserted += 1
                logger.debug("Synced review %d → wc_comment %d",
                             review.basalam_review_id, wc_comment_id)
            else:
                result.errors += 1
                result.error_messages.append(
                    f"WordPress rejected review {review.basalam_review_id}"
                )
        except Exception as e:
            result.errors += 1
            result.error_messages.append(str(e))
            logger.error("Error pushing review %d: %s",
                         review.basalam_review_id, e)

    db.log_sync(mode, result.reviews_fetched, result.reviews_inserted,
                result.reviews_skipped, result.errors, result.error_messages)

    logger.info(
        "Sync done — fetched=%d inserted=%d skipped=%d errors=%d",
        result.reviews_fetched, result.reviews_inserted,
        result.reviews_skipped, result.errors,
    )
    return result
