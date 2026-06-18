"""
Lightweight HTTP log server running as a daemon thread alongside the scheduler.

Endpoints (all require X-BRP-API-Key header):
  GET  /logs?lines=N  — return last N lines of the log file
  DELETE /logs        — truncate the log file
"""
import hmac
import logging
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlparse

from .config import get_settings

logger = logging.getLogger(__name__)


class _LogHandler(BaseHTTPRequestHandler):

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

    def _parse_path(self) -> str:
        return urlparse(self.path).path

    def do_GET(self) -> None:  # noqa: N802
        if self._parse_path() != "/logs":
            self._send(404, b'{"error":"not found"}')
            return
        if not self._auth():
            self._send(401, b'{"error":"unauthorized"}')
            return

        params = parse_qs(urlparse(self.path).query)
        try:
            n = int(params.get("lines", ["200"])[0])
            n = max(1, min(5000, n))
        except ValueError:
            n = 200

        log_path = Path(get_settings().log_file)
        if not log_path.exists():
            self._send(200, b"(log file not yet created)", "text/plain; charset=utf-8")
            return

        content = log_path.read_bytes()
        lines = content.splitlines(keepends=True)
        tail = b"".join(lines[-n:])
        self._send(200, tail, "text/plain; charset=utf-8")

    def do_DELETE(self) -> None:  # noqa: N802
        if self._parse_path() != "/logs":
            self._send(404, b'{"error":"not found"}')
            return
        if not self._auth():
            self._send(401, b'{"error":"unauthorized"}')
            return

        log_path = Path(get_settings().log_file)
        try:
            log_path.write_bytes(b"")
            self._send(200, b'{"status":"cleared"}')
            logger.info("Log file cleared via log server")
        except OSError as exc:
            logger.error("Failed to clear log file: %s", exc)
            self._send(500, b'{"error":"could not clear log"}')


def start_log_server() -> threading.Thread | None:
    cfg = get_settings()
    if not cfg.log_server_enabled:
        logger.info("Log server disabled (LOG_SERVER_ENABLED=false)")
        return None
    port = cfg.log_server_port
    # Binds to all interfaces — restrict port 8101 in firewall to WP hosting IP
    server = ThreadingHTTPServer(("0.0.0.0", port), _LogHandler)
    thread = threading.Thread(target=server.serve_forever,
                              name="log-server", daemon=True)
    thread.start()
    logger.info("Log server listening on port %d", port)
    return thread
