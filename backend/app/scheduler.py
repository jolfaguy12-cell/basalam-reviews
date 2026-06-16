import logging
from apscheduler.schedulers.blocking import BlockingScheduler
from apscheduler.triggers.interval import IntervalTrigger

from .config import get_settings
from .sync import run_sync

logger = logging.getLogger(__name__)


def start():
    cfg = get_settings()
    scheduler = BlockingScheduler(timezone="Asia/Tehran")

    scheduler.add_job(
        run_sync,
        trigger=IntervalTrigger(minutes=cfg.sync_interval_minutes),
        id="sync",
        name="Basalam review sync",
        max_instances=1,
        replace_existing=True,
        kwargs={"mode": "incremental"},
    )

    logger.info(
        "Scheduler started — sync every %d minutes", cfg.sync_interval_minutes
    )
    try:
        scheduler.start()
    except (KeyboardInterrupt, SystemExit):
        logger.info("Scheduler stopped")
