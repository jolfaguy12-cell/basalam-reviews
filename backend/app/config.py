"""
Environment-aware configuration.

APP_ENV controls which credential set is active:
  - "dev"        → DEV_WORDPRESS_* credentials, data/reviews_dev.db, data/debug_dev.log
  - "production" → PROD_WORDPRESS_* credentials, data/reviews_prod.db, data/debug_prod.log

Switching environments requires only changing APP_ENV in .env and restarting.
Dev and production databases/logs are always separate — there is no cross-write risk.
"""
from dataclasses import dataclass
from functools import lru_cache

from pydantic_settings import BaseSettings


class _RawSettings(BaseSettings):
    """Pydantic binding layer — reads raw values from .env."""

    app_env: str = "dev"

    # ── Dev WordPress credentials ─────────────────────────────────────────────
    dev_wordpress_endpoint: str = ""
    dev_wordpress_api_key: str = ""
    dev_wordpress_plugin_secret: str = ""

    # ── Production WordPress credentials ─────────────────────────────────────
    prod_wordpress_endpoint: str = ""
    prod_wordpress_api_key: str = ""
    prod_wordpress_plugin_secret: str = ""

    # ── DataHub HTTP API (shared across envs) ─────────────────────────────────
    data_hub_endpoint: str = ""
    data_hub_api_key: str = ""

    # ── Basalam (shared across envs) ─────────────────────────────────────────
    basalam_endpoint: str = "https://services.basalam.com"
    basalam_vendor_id: int = 1399163
    basalam_vendor_identifier: str = "behdashtik"

    # ── Service ───────────────────────────────────────────────────────────────
    service_port: int = 8100
    log_server_port: int = 8101
    log_server_enabled: bool = True

    # ── Crawler tuning ────────────────────────────────────────────────────────
    crawl_page_limit: int = 20
    crawl_delay_seconds: float = 2.0
    sync_interval_minutes: int = 360
    crawl_interval_hours: int = 24
    block_star_only_reviews: bool = False

    class Config:
        env_file = ".env"
        env_file_encoding = "utf-8"
        case_sensitive = False


@dataclass(frozen=True)
class Settings:
    """
    Resolved active configuration.
    All env-specific values are already selected — callers never inspect APP_ENV.
    Field names are identical to the old single-env Settings to preserve compatibility.
    """
    app_env: str
    env_label: str       # "DEV" or "PRODUCTION"
    is_dev: bool

    # Active WordPress credentials (resolved from dev or prod prefix)
    wordpress_endpoint: str
    wordpress_api_key: str
    wordpress_plugin_secret: str

    data_hub_endpoint: str
    data_hub_api_key: str

    basalam_endpoint: str
    basalam_vendor_id: int
    basalam_vendor_identifier: str

    # Paths are env-scoped (e.g. data/reviews_dev.db vs data/reviews_prod.db)
    internal_db_path: str
    log_file: str
    plugin_log_file: str

    service_port: int
    log_server_port: int
    log_server_enabled: bool

    crawl_page_limit: int
    crawl_delay_seconds: float
    sync_interval_minutes: int
    crawl_interval_hours: int
    block_star_only_reviews: bool


@lru_cache
def get_settings() -> Settings:
    raw = _RawSettings()
    is_dev = raw.app_env.lower() in ("dev", "development")
    env_label = "DEV" if is_dev else "PRODUCTION"
    slug = "dev" if is_dev else "prod"

    return Settings(
        app_env=raw.app_env,
        env_label=env_label,
        is_dev=is_dev,
        wordpress_endpoint=(
            raw.dev_wordpress_endpoint if is_dev else raw.prod_wordpress_endpoint
        ),
        wordpress_api_key=(
            raw.dev_wordpress_api_key if is_dev else raw.prod_wordpress_api_key
        ),
        wordpress_plugin_secret=(
            raw.dev_wordpress_plugin_secret if is_dev else raw.prod_wordpress_plugin_secret
        ),
        data_hub_endpoint=raw.data_hub_endpoint,
        data_hub_api_key=raw.data_hub_api_key,
        basalam_endpoint=raw.basalam_endpoint,
        basalam_vendor_id=raw.basalam_vendor_id,
        basalam_vendor_identifier=raw.basalam_vendor_identifier,
        internal_db_path=f"data/reviews_{slug}.db",
        log_file=f"data/debug_{slug}.log",
        plugin_log_file=f"data/plugin_{slug}.log",
        service_port=raw.service_port,
        log_server_port=raw.log_server_port,
        log_server_enabled=raw.log_server_enabled,
        crawl_page_limit=raw.crawl_page_limit,
        crawl_delay_seconds=raw.crawl_delay_seconds,
        sync_interval_minutes=raw.sync_interval_minutes,
        crawl_interval_hours=raw.crawl_interval_hours,
        block_star_only_reviews=raw.block_star_only_reviews,
    )
