#!/usr/bin/env python3
"""
basalam-review backend — CLI entry point

Commands:
  full-sync     Crawl all Basalam reviews and push new ones to WordPress
  sync          Incremental sync (same as full-sync; scheduler uses this)
  worker        Start the continuous scheduler (blocks)
  status        Print DB stats and service health
  fetch-mappings  Pull all product mappings from the Data Hub
"""
import argparse
import json
import logging
import logging.handlers
import sys
from pathlib import Path

logger = logging.getLogger(__name__)


def _configure_logging(log_file: str) -> None:
    Path(log_file).parent.mkdir(parents=True, exist_ok=True)
    fmt = logging.Formatter(
        "%(asctime)s %(levelname)s %(name)s — %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S",
    )
    root = logging.getLogger()
    root.setLevel(logging.INFO)

    sh = logging.StreamHandler(sys.stdout)
    sh.setFormatter(fmt)
    root.addHandler(sh)

    fh = logging.handlers.RotatingFileHandler(
        log_file, maxBytes=500 * 1024, backupCount=3, encoding="utf-8"
    )
    fh.setFormatter(fmt)
    root.addHandler(fh)


def cmd_sync(mode: str):
    from .sync import run_sync
    result = run_sync(mode=mode)
    print(json.dumps(result.__dict__, ensure_ascii=False, indent=2))


def cmd_worker():
    from .log_server import start_log_server
    from .scheduler import start
    start_log_server()
    start()


def cmd_status():
    from .config import get_settings
    from .database import Database
    from .wordpress_client import WordPressClient
    from .datahub_client import DataHubClient

    cfg = get_settings()
    db = Database(cfg.internal_db_path)
    wp = WordPressClient()
    hub = DataHubClient()

    stats = db.stats()
    stats["wordpress_healthy"] = wp.health()
    stats["datahub_healthy"] = hub.health()
    stats["env"]              = cfg.env_label
    stats["db_path"]          = cfg.internal_db_path
    stats["wordpress_url"]    = cfg.wordpress_endpoint
    print(json.dumps(stats, ensure_ascii=False, indent=2))


def cmd_fetch_mappings():
    from .config import get_settings
    from .database import Database
    from .datahub_client import DataHubClient

    cfg = get_settings()
    db = Database(cfg.internal_db_path)
    hub = DataHubClient()

    mappings = hub.get_all_mappings()
    for m in mappings:
        db.upsert_mapping(
            int(m["basalam_product_id"]),
            int(m["wc_product_id"]),
            m.get("basalam_title", ""),
            m.get("wc_title", ""),
        )
        db.set_wc_product_id(int(m["basalam_product_id"]), int(m["wc_product_id"]))
    print(f"Fetched and stored {len(mappings)} product mappings.")


def _log_startup_banner(cfg) -> None:
    sep = "=" * 60
    logger.info(sep)
    logger.info("[%s] Basalam Review Backend", cfg.env_label)
    logger.info("  Environment : %s", cfg.env_label)
    logger.info("  Database    : %s", cfg.internal_db_path)
    logger.info("  WordPress   : %s", cfg.wordpress_endpoint or "(not configured)")
    logger.info("  Log file    : %s", cfg.log_file)
    logger.info(sep)


def main():
    from .config import get_settings
    cfg = get_settings()
    _configure_logging(cfg.log_file)
    _log_startup_banner(cfg)

    parser = argparse.ArgumentParser(prog="basalam-review")
    sub = parser.add_subparsers(dest="command")

    sub.add_parser("full-sync", help="Crawl all reviews and push to WordPress")
    sub.add_parser("sync", help="Incremental sync")
    sub.add_parser("worker", help="Start continuous scheduler (blocks)")
    sub.add_parser("status", help="Print DB stats and health")
    sub.add_parser("fetch-mappings", help="Pull product mappings from Data Hub")

    args = parser.parse_args()

    if args.command in ("full-sync", "sync"):
        cmd_sync("full")
    elif args.command == "worker":
        cmd_worker()
    elif args.command == "status":
        cmd_status()
    elif args.command == "fetch-mappings":
        cmd_fetch_mappings()
    else:
        parser.print_help()
        sys.exit(1)


if __name__ == "__main__":
    main()
