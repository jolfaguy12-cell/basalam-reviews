import time
import logging
import urllib.request
import urllib.error
import json
from typing import Iterator

from .config import get_settings
from .models import Review, Reply

logger = logging.getLogger(__name__)


class BasalamCrawler:
    def __init__(self):
        cfg = get_settings()
        self._base = cfg.basalam_endpoint
        self._vendor_id = cfg.basalam_vendor_id
        self._limit = cfg.crawl_page_limit
        self._delay = cfg.crawl_delay_seconds
        self._headers = {
            "User-Agent": "Mozilla/5.0",
            "Accept": "application/json",
        }

    def _get(self, url: str) -> dict:
        req = urllib.request.Request(url, headers=self._headers)
        with urllib.request.urlopen(req, timeout=30) as resp:
            return json.load(resp)

    def fetch_summary(self) -> dict:
        url = f"{self._base}/web/v1/review/vendor/{self._vendor_id}/reviews/group"
        return self._get(url)

    def iter_all_reviews(self, known_ids: set | None = None) -> Iterator[Review]:
        """
        Yields reviews newest-first. In incremental mode (known_ids provided),
        stops automatically after 2 consecutive pages where every review is
        already known — avoids unnecessary load on the Basalam API.
        """
        offset = 0
        page = 1
        unchanged_pages = 0

        while True:
            url = (
                f"{self._base}/web/v1/review/vendor/{self._vendor_id}"
                f"/reviews?limit={self._limit}&offset={offset}"
            )
            try:
                data = self._get(url)
            except urllib.error.HTTPError as e:
                logger.error("Basalam API error page=%d status=%d", page, e.code)
                break
            except Exception as e:
                logger.error("Basalam fetch error page=%d: %s", page, e)
                break

            reviews = data.get("reviews", [])
            if not reviews:
                break

            page_has_new = False
            for r in reviews:
                rev = self._parse(r)
                if known_ids is not None and rev.basalam_review_id not in known_ids:
                    page_has_new = True
                yield rev

            logger.info("Crawled page %d — %d reviews", page, len(reviews))

            if known_ids is not None:
                if page_has_new:
                    unchanged_pages = 0
                else:
                    unchanged_pages += 1
                    if unchanged_pages >= 2:
                        logger.info("Early stop — 2 consecutive fully-known pages")
                        break

            if not data.get("has_next"):
                break

            offset += self._limit
            page += 1
            time.sleep(self._delay)

    def _parse(self, raw: dict) -> Review:
        product = raw.get("product") or {}
        replies = [
            Reply(
                author_name=a.get("user", {}).get("name", ""),
                description=a.get("description", ""),
                basalam_answer_id=a.get("id"),
            )
            for a in raw.get("answers", [])
        ]
        return Review(
            basalam_review_id=raw["id"],
            basalam_product_id=int(raw.get("productId", 0)),
            product_title=product.get("title", ""),
            vendor_id=product.get("vendor_id", get_settings().basalam_vendor_id),
            user_name=raw["user"]["name"],
            user_id=raw["user"]["id"],
            star=raw["star"],
            description=raw.get("description", ""),
            created_at=raw["createdAt"][:19],
            replies=replies,
        )
