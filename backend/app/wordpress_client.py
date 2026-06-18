import hmac
import hashlib
import json
import logging
import urllib.request
import urllib.error
from typing import Optional

from .config import get_settings
from .models import Review

logger = logging.getLogger(__name__)


def _sign(secret: str, body: bytes) -> str:
    return hmac.new(secret.encode(), body, hashlib.sha256).hexdigest()


class WordPressClient:
    def __init__(self):
        cfg = get_settings()
        self._endpoint = cfg.wordpress_endpoint.rstrip("/")
        self._api_key = cfg.wordpress_api_key
        self._secret = cfg.wordpress_plugin_secret

    def _post(self, path: str, payload: dict) -> Optional[dict]:
        if not self._endpoint:
            logger.warning("WORDPRESS_ENDPOINT not configured")
            return None

        body = json.dumps(payload, ensure_ascii=False).encode()
        signature = _sign(self._secret, body)

        headers = {
            "Content-Type": "application/json",
            "X-BRP-Signature": f"sha256={signature}",
            "X-BRP-API-Key": self._api_key,
        }
        url = f"{self._endpoint}{path}"
        req = urllib.request.Request(url, data=body, headers=headers, method="POST")
        try:
            with urllib.request.urlopen(req, timeout=20) as resp:
                return json.load(resp)
        except urllib.error.HTTPError as e:
            body_err = e.read().decode(errors="replace")
            logger.error("WordPress HTTP %d for %s: %s", e.code, path, body_err)
            return None
        except Exception as e:
            logger.error("WordPress error for %s: %s", path, e)
            return None

    def push_review(self, review: Review) -> Optional[int]:
        """Push a review to the WordPress plugin. Returns the new wc_comment_id."""
        payload = {
            "basalam_review_id": review.basalam_review_id,
            "wc_product_id": review.wc_product_id,
            "user_name": review.user_name,
            "star": review.star,
            "description": review.description,
            "created_at": review.created_at,
            "replies": [
                {"author_name": r.author_name, "description": r.description}
                for r in review.replies
            ],
        }
        result = self._post("/wp-json/basalam-review/v1/receive", payload)
        if result and result.get("wc_comment_id"):
            return int(result["wc_comment_id"])
        if result and result.get("status") == "failed":
            return None
        return None

    def health(self) -> bool:
        if not self._endpoint:
            return False
        url = f"{self._endpoint}/wp-json/basalam-review/v1/health"
        req = urllib.request.Request(url, headers={"Accept": "application/json"})
        try:
            with urllib.request.urlopen(req, timeout=10) as resp:
                data = json.load(resp)
                return data.get("status") == "ok"
        except Exception:
            return False
