import hmac
import hashlib
import json
import logging
import urllib.request
import urllib.error
from typing import Optional

from .config import get_settings

logger = logging.getLogger(__name__)


class DataHubClient:
    def __init__(self):
        cfg = get_settings()
        self._endpoint = cfg.data_hub_endpoint.rstrip("/")
        self._api_key = cfg.data_hub_api_key

    def _headers(self) -> dict:
        return {
            "Authorization": f"Bearer {self._api_key}",
            "Accept": "application/json",
            "Content-Type": "application/json",
        }

    def _get(self, path: str) -> Optional[dict]:
        if not self._endpoint:
            logger.warning("DATA_HUB_ENDPOINT not configured")
            return None
        url = f"{self._endpoint}{path}"
        req = urllib.request.Request(url, headers=self._headers())
        try:
            with urllib.request.urlopen(req, timeout=15) as resp:
                return json.load(resp)
        except urllib.error.HTTPError as e:
            logger.error("DataHub HTTP %d for %s", e.code, path)
            return None
        except Exception as e:
            logger.error("DataHub error for %s: %s", path, e)
            return None

    def get_wc_product_id(self, basalam_product_id: int) -> Optional[int]:
        """Look up the WooCommerce product ID for a given Basalam product ID."""
        data = self._get(f"/api/v1/products/match?basalam_id={basalam_product_id}")
        if data and data.get("wc_product_id"):
            return int(data["wc_product_id"])
        return None

    def get_all_mappings(self) -> list[dict]:
        """Fetch all product mappings at once (for bulk import)."""
        data = self._get("/api/v1/products/mappings")
        if data and isinstance(data.get("mappings"), list):
            return data["mappings"]
        return []

    def health(self) -> bool:
        data = self._get("/api/v1/health")
        return bool(data and data.get("status") == "ok")
