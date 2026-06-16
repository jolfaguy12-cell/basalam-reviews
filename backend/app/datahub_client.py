import logging
from typing import Optional

import urllib.request
import urllib.error
import json

from .config import get_settings

logger = logging.getLogger(__name__)


class DataHubClient:
    """
    Fetches Basalam → WooCommerce product mappings from the DataHub HTTP API.
    Configure DATA_HUB_ENDPOINT and DATA_HUB_API_KEY in .env.

    Expected endpoints (provided by wordpress-data-hub server2/data_api.py):
      GET /api/v1/mapping/basalam/{basalam_product_id}?vendor_id={id}
          → {"data": {"basalam_product_id": ..., "wc_product_id": ...}}
      GET /api/v1/mapping/basalam?vendor_id={id}
          → {"data": {"count": N, "mappings": [...]}}
      GET /api/v1/health
          → {"data": {...}}
    """

    def __init__(self):
        cfg = get_settings()
        self._endpoint  = (cfg.data_hub_endpoint or "").rstrip("/")
        self._api_key   = cfg.data_hub_api_key
        self._vendor_id = cfg.basalam_vendor_id
        self._warned    = False

    def _request(self, path: str) -> Optional[dict]:
        if not self._endpoint:
            self._warn_once()
            return None
        url = f"{self._endpoint}{path}"
        req = urllib.request.Request(url)
        if self._api_key:
            req.add_header("X-Hub-API-Key", self._api_key)
        try:
            with urllib.request.urlopen(req, timeout=10) as resp:
                body = json.loads(resp.read())
                return body.get("data", body)
        except urllib.error.HTTPError as e:
            if e.code != 404:
                logger.error("DataHub API error %s for %s", e.code, url)
        except Exception as e:
            logger.error("DataHub request failed (%s): %s", url, e)
        return None

    def _warn_once(self):
        if not self._warned:
            logger.warning(
                "DATA_HUB_ENDPOINT not configured — product matching disabled. "
                "Set DATA_HUB_ENDPOINT and DATA_HUB_API_KEY in .env"
            )
            self._warned = True

    def get_wc_product_id(self, basalam_product_id: int) -> Optional[int]:
        data = self._request(
            f"/api/v1/mapping/basalam/{basalam_product_id}?vendor_id={self._vendor_id}"
        )
        if data and "wc_product_id" in data:
            return int(data["wc_product_id"])
        return None

    def get_all_mappings(self) -> list[dict]:
        data = self._request(f"/api/v1/mapping/basalam?vendor_id={self._vendor_id}")
        if isinstance(data, dict) and "mappings" in data:
            return data["mappings"]
        return []

    def health(self) -> bool:
        if not self._endpoint:
            return False
        data = self._request("/api/v1/health")
        return data is not None
