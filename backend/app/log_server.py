"""
Lightweight HTTP server running as a daemon thread alongside the scheduler.

Endpoints (all require X-BRP-API-Key header):
  GET    /logs?lines=N  — tail of data/plugin.log (plugin-pushed events)
  DELETE /logs          — clear data/plugin.log
  POST   /logs          — receive a log event from the plugin and append it
  POST   /sync          — trigger an incremental sync in a background thread
"""
import hmac
import json
import logging
import threading
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlparse

from .config import get_settings

logger = logging.getLogger(__name__)

_sync_lock = threading.Lock()


class _Handler(BaseHTTPRequestHandler):

    def log_message(self, format, *args):  # noqa: A002
        logger.debug("log-server %s", format % args)

    def _auth(self) -> bool:
        cfg = get_settings()
        provided = self.headers.get("X-BRP-API-Key", "")
        return bool(provided) and hmac.compare_digest(cfg.wordpress_api_key, provided)

    def _send(self, code: int, body: bytes,
              content_type: str = "application/json") -> None:
        self.send_response(code)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _path(self) -> str:
        return urlparse(self.path).path

    def _read_body(self) -> bytes:
        length = int(self.headers.get("Content-Length", 0))
        return self.rfile.read(length) if length else b""

    # ── GET /logs ─────────────────────────────────────────────────────────────

    def do_GET(self) -> None:  # noqa: N802
        if self._path() != "/logs":
            self._send(404, b'{"error":"not found"}')
            return
        if not self._auth():
            self._send(401, b'{"error":"unauthorized"}')
            return

        params = parse_qs(urlparse(self.path).query)
        try:
            n = max(1, min(5000, int(params.get("lines", ["200"])[0])))
        except ValueError:
            n = 200

        log_path = Path(get_settings().plugin_log_file)
        if not log_path.exists():
            self._send(200, b"(no plugin logs yet)", "text/plain; charset=utf-8")
            return

        lines = log_path.read_bytes().splitlines(keepends=True)
        tail = b"".join(lines[-n:])
        self._send(200, tail, "text/plain; charset=utf-8")

    # ── DELETE /logs ──────────────────────────────────────────────────────────

    def do_DELETE(self) -> None:  # noqa: N802
        if self._path() != "/logs":
            self._send(404, b'{"error":"not found"}')
            return
        if not self._auth():
            self._send(401, b'{"error":"unauthorized"}')
            return

        log_path = Path(get_settings().plugin_log_file)
        try:
            log_path.write_bytes(b"")
            self._send(200, b'{"status":"cleared"}')
            logger.info("Plugin log cleared via log server")
        except OSError as exc:
            logger.error("Failed to clear plugin log: %s", exc)
            self._send(500, b'{"error":"could not clear log"}')

    # ── POST /logs or POST /sync ──────────────────────────────────────────────

    def do_POST(self) -> None:  # noqa: N802
        p = self._path()
        if p == "/logs":
            self._handle_post_log()
        elif p == "/sync":
            self._handle_post_sync()
        else:
            self._send(404, b'{"error":"not found"}')

    def _handle_post_log(self) -> None:
        if not self._auth():
            self._send(401, b'{"error":"unauthorized"}')
            return

        raw = self._read_body()
        try:
            event = json.loads(raw)
        except (json.JSONDecodeError, ValueError):
            self._send(400, b'{"error":"invalid json"}')
            return

        level   = str(event.get("level", "info")).upper()
        message = str(event.get("message", ""))
        context = event.get("context", {})

        ts = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        ctx_str = json.dumps(context, ensure_ascii=False) if context else ""
        line = f"[{ts}] {level} {message}"
        if ctx_str:
            line += f" | {ctx_str}"
        line += "\n"

        cfg = get_settings()
        log_path = Path(cfg.plugin_log_file)
        log_path.parent.mkdir(parents=True, exist_ok=True)
        try:
            with log_path.open("a", encoding="utf-8") as f:
                f.write(line)
            self._send(200, b'{"status":"ok"}')
        except OSError as exc:
            logger.error("Failed to write plugin log: %s", exc)
            self._send(500, b'{"error":"write failed"}')

    def _handle_post_sync(self) -> None:
        if not self._auth():
            self._send(401, b'{"error":"unauthorized"}')
            return

        if not _sync_lock.acquire(blocking=False):
            self._send(200, b'{"status":"already_running"}')
            return

        def _run():
            try:
                from .sync import run_sync
                run_sync("incremental")
            except Exception as exc:
                logger.error("Manual sync failed: %s", exc)
            finally:
                _sync_lock.release()

        thread = threading.Thread(target=_run, name="manual-sync", daemon=True)
        thread.start()
        self._send(200, b'{"status":"started"}')
        logger.info("Manual sync triggered via log server")


def start_log_server() -> threading.Thread | None:
    cfg = get_settings()
    if not cfg.log_server_enabled:
        logger.info("Log server disabled (LOG_SERVER_ENABLED=false)")
        return None
    port = cfg.log_server_port
    # Binds to all interfaces — restrict port in firewall to WordPress hosting IP
    server = ThreadingHTTPServer(("0.0.0.0", port), _Handler)
    thread = threading.Thread(target=server.serve_forever,
                              name="log-server", daemon=True)
    thread.start()
    logger.info("Log server listening on port %d", port)
    return thread
