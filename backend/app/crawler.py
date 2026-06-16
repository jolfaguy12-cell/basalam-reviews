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

    def iter_all_reviews(self) -> Iterator[Review]:
        offset = 0
        page = 1
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

            for r in reviews:
                yield self._parse(r)

            logger.info("Crawled page %d — %d reviews", page, len(reviews))

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
