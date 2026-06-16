from pydantic_settings import BaseSettings
from pydantic import Field
from functools import lru_cache


class Settings(BaseSettings):
    app_env: str = "development"

    # WordPress plugin
    wordpress_endpoint: str = ""
    wordpress_api_key: str = ""
    wordpress_plugin_secret: str = ""

    # DataHub HTTP API
    data_hub_endpoint: str = ""
    data_hub_api_key: str = ""

    # Basalam
    basalam_endpoint: str = "https://services.basalam.com"
    basalam_vendor_id: int = 1399163
    basalam_vendor_identifier: str = "behdashtik"

    # Internal SQLite DB
    internal_db_path: str = "data/reviews.db"

    # Service
    service_port: int = 8100

    # Crawler tuning
    crawl_page_limit: int = 20
    crawl_delay_seconds: float = 0.5
    sync_interval_minutes: int = 30

    class Config:
        env_file = ".env"
        env_file_encoding = "utf-8"
        case_sensitive = False


@lru_cache
def get_settings() -> Settings:
    return Settings()
