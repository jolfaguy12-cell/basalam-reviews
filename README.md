# Behdashtik Basalam Sync — v1.2.3

Continuously syncs Basalam marketplace reviews into WooCommerce.
Two-component system: a Python backend service on Server 2 and a lightweight WordPress plugin on the site.

---

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│                    Basalam API                          │
│           services.basalam.com                          │
└────────────────────┬────────────────────────────────────┘
                     │ crawl (paged, rate-limited, 429-retry)
                     ▼
┌─────────────────────────────────────────────────────────┐
│                   SERVER 2 (Ubuntu)                     │
│                                                         │
│  backend/                                               │
│  ├── crawler.py        fetch all reviews & replies      │
│  ├── database.py       SQLite — dedup, sync state       │
│  ├── datahub_client.py resolve Basalam → WC product IDs │
│  ├── sync.py           crawl → match → push pipeline    │
│  ├── scheduler.py      APScheduler, runs every 6 hours  │
│  ├── log_server.py     HTTP log viewer (port 8101)      │
│  └── main.py           CLI entry point                  │
│                                                         │
│  Data Hub API (mainhub.behdashtik.ir)                   │
│  └── GET /api/v1/mapping/basalam/{id}  product mapping  │
│                                                         │
│  Log Server (port 8101)                                 │
│  ├── GET  /logs?lines=N  — tail of debug.log            │
│  └── DELETE /logs        — clear debug.log              │
└────────────────────┬────────────────────────────────────┘
                     │ HMAC-signed HTTPS POST
                     │ /wp-json/basalam-review/v1/receive
                     ▼
┌─────────────────────────────────────────────────────────┐
│              WORDPRESS PLUGIN (site server)             │
│                                                         │
│  Thin, secure connector only:                           │
│  ├── Authenticate incoming requests (HMAC + API key)    │
│  ├── Insert pre-processed reviews into wp_comments      │
│  ├── Insert seller replies as child comments            │
│  ├── Apply display settings (prefix, suffix, names)     │
│  ├── Recalculate WooCommerce rating synchronously       │
│  └── Proxy log viewer requests to log server            │
│                                                         │
│  REST endpoints:                                        │
│  ├── GET  /wp-json/basalam-review/v1/health  (public)   │
│  └── POST /wp-json/basalam-review/v1/receive (signed)   │
└─────────────────────────────────────────────────────────┘
                     │
                     ▼
         WooCommerce wp_comments table
```

---

## Responsibility Split

### WordPress Plugin — Lightweight Connector Only

| Responsibility | Owner |
|----------------|-------|
| Authenticate requests (HMAC-SHA256 + API key) | Plugin |
| Insert reviews into `wp_comments` | Plugin |
| Apply name prefix / suffix | Plugin |
| Randomize seller reply author name | Plugin |
| Attach product thumbnail to review | Plugin |
| Duplicate detection (`basalam_review_id` meta) | Plugin |
| Recalculate WooCommerce rating (synchronous) | Plugin |
| Unapprove star-only reviews (no text) on activation | Plugin |
| Proxy log viewer requests to backend log server | Plugin |
| Product ID matching / mapping logic | Server 2 only |
| DataHub integration | Server 2 only |
| Crawling Basalam API | Server 2 only |
| Scheduling / cron / retry logic | Server 2 only |
| Deduplication database | Server 2 only |

### Server 2 — Main Processing Center

| Responsibility | Module |
|----------------|--------|
| Crawl Basalam API (paginated, rate-limited, 429 retry) | `crawler.py` |
| SHA-256 content deduplication | `database.py` |
| Sync state tracking | `database.py` |
| Product ID mapping via Data Hub HTTP API | `datahub_client.py` |
| Sign and push reviews to WordPress (HTTPS, retry×3) | `wordpress_client.py` / `sync.py` |
| Orchestrate the full pipeline | `sync.py` |
| Schedule incremental syncs (every 6 hours) | `scheduler.py` |
| Rotating file log + HTTP log server (port 8101) | `log_server.py` / `main.py` |
| CLI commands for manual operations | `main.py` |

---

## Backend Setup

```bash
cd /root/behdashtik-basalam-sync/backend
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt
cp .env.example .env   # fill in credentials
.venv/bin/python -m app.main status
```

### Environment variables

```env
APP_ENV=production

# WordPress plugin credentials (copy from plugin Settings page)
WORDPRESS_ENDPOINT=https://behdashtik.ir
WORDPRESS_API_KEY=<from plugin settings>
WORDPRESS_PLUGIN_SECRET=<from plugin settings>

# Data Hub HTTP API (remote)
DATA_HUB_ENDPOINT=https://mainhub.behdashtik.ir
DATA_HUB_API_KEY=<from data hub config>

# Basalam
BASALAM_ENDPOINT=https://services.basalam.com
BASALAM_VENDOR_ID=1399163
BASALAM_VENDOR_IDENTIFIER=behdashtik

# Runtime
INTERNAL_DB_PATH=data/reviews.db
SERVICE_PORT=8100
CRAWL_PAGE_LIMIT=20
CRAWL_DELAY_SECONDS=2.0
SYNC_INTERVAL_MINUTES=360

# Debug log server
LOG_FILE=data/debug.log
LOG_SERVER_PORT=8101
```

### CLI commands

```bash
.venv/bin/python -m app.main full-sync        # crawl all reviews and push to WordPress
.venv/bin/python -m app.main sync             # incremental sync (new/changed only)
.venv/bin/python -m app.main worker           # start continuous scheduler + log server (blocks)
.venv/bin/python -m app.main status           # DB stats + connection health
.venv/bin/python -m app.main fetch-mappings   # pull all product mappings from Data Hub
```

### Systemd

```bash
cp systemd/basalam-review.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now basalam-review
journalctl -u basalam-review -f
```

The service runs from `/root/behdashtik-basalam-sync/backend` using the venv Python interpreter and auto-restarts on failure.
Logs are written to both journalctl and `data/debug.log` (500 KB rotating, 3 backups).

---

## WordPress Plugin Setup

**Install:**
Upload `basalam-review-plugin-v1.2.3.zip` via **WP Admin → Plugins → Add New → Upload Plugin**.

**Configure:**
1. Go to **Settings → Basalam Review**
2. Click **↻ Generate** next to API Key
3. Click **↻ Generate** next to Plugin Secret
4. Save Settings
5. Copy both values into `backend/.env` on Server 2

**Plugin settings — five cards:**

| Card | Settings |
|------|---------|
| Authentication | API Key, Plugin Secret, Regenerate Both |
| Review Display | Name Prefix, Name Suffix, Auto-approve, Attach product image |
| Seller Replies | Randomize name, Name pool |
| Debug Logs | Log Server URL, Log API Key |
| *(Log Viewer)* | View Logs button, Clear Logs button, line count selector |
| Maintenance | Unapprove star-only reviews button |

**REST endpoints:**

| Method | Path | Auth |
|--------|------|------|
| `GET` | `/wp-json/basalam-review/v1/health` | None (public) |
| `POST` | `/wp-json/basalam-review/v1/receive` | API Key + HMAC-SHA256 |

---

## Debug Log Viewer

The backend writes all logs to `data/debug.log` and serves them over a minimal HTTP server on port 8101.
The WordPress admin panel proxies requests to this server so logs are readable without SSH.

**Setup:**
1. Open port `8101` in the VPS firewall, restricted to the WordPress hosting server IP
2. In plugin settings → **Debug Logs**: enter `http://<server-ip>:8101` as Log Server URL
3. Leave **Log API Key** blank — it defaults to the API Key from Card 1
4. Save Settings → click **View Logs**

**Log server endpoints** (require `X-BRP-API-Key` header):

```
GET    /logs?lines=200   — returns last N lines of debug.log
DELETE /logs             — clears debug.log
```

---

## Data Hub Integration

The Data Hub runs at `https://mainhub.behdashtik.ir`. The backend connects via HTTP API using `X-Hub-API-Key` authentication.

**Mapping endpoints used:**

```
GET /api/v1/mapping/basalam/{basalam_product_id}?vendor_id=1399163
    → {"data": {"basalam_product_id": ..., "wc_product_id": ...}}

GET /api/v1/mapping/basalam?vendor_id=1399163
    → {"data": {"count": N, "mappings": [...]}}

GET /api/v1/health
    → {"data": {"mirror_db": "ok", ...}}
```

**Note:** `DATA_HUB_ENDPOINT` must be the base URL only (e.g. `https://mainhub.behdashtik.ir`) — the client appends `/api/v1/...` paths automatically.

---

## Security

| Layer | Mechanism |
|-------|-----------|
| Transport | HTTPS — all Server 2 → WordPress traffic is SSL-encrypted |
| Request authentication | `X-BRP-API-Key` header, verified with `hash_equals()` |
| Request integrity | `X-BRP-Signature: sha256=HMAC(secret, body)` |
| Log server auth | `X-BRP-API-Key` header, `hmac.compare_digest()` |
| Duplicate prevention | `basalam_review_id` meta checked before insert |
| No secrets in code | All credentials in `.env` (gitignored) or WP options |
| Plugin receive-only | Plugin never initiates outbound connections (except log proxy) |

---

## Data Flow

1. **Crawl** — Server 2 fetches all reviews from Basalam API (20/page, rate-limited, 429 → 60s wait + retry)
2. **Dedup** — SHA-256 hash detects new or changed reviews; SQLite stores state
3. **Match** — Server 2 resolves Basalam product IDs → WooCommerce IDs via Data Hub API
4. **Push** — Server 2 POSTs each review to the plugin over HTTPS (HMAC-signed, retries up to 3×)
5. **Insert** — Plugin verifies auth, checks duplicates, inserts into `wp_comments`
6. **Reply** — Seller replies inserted as child comments (try/finally ensures WC hook is always restored)
7. **Recalc** — Plugin recalculates WooCommerce product average rating synchronously via `WC_Comments`
8. **Log** — Server 2 records sync result in SQLite and appends to rotating `debug.log`

---

## Production Deployment Checklist

- [x] Plugin installed on `behdashtik.ir` (v1.2.3)
- [x] Data Hub connected via HTTP API (`https://mainhub.behdashtik.ir`, 346 mappings)
- [x] Auto-sync running every 6 hours via systemd (`basalam-review.service`)
- [x] HTTPS verified (all WordPress traffic encrypted)
- [x] API key + secret configured in `backend/.env`
- [x] `WORDPRESS_ENDPOINT=https://behdashtik.ir` set in production `.env`
- [x] Rotating file log enabled (`data/debug.log`, 500 KB × 3)
- [x] Log server running on port 8101 alongside scheduler
- [ ] Open port 8101 in VPS firewall for WordPress hosting IP
- [ ] Configure Log Server URL in plugin settings

---

## Rollback Tags

| Tag | Commit | Description |
|-----|--------|-------------|
| `v1.2.3` | `26516ce` | Debug log viewer, star-only unapproval, hard-mode resilience fixes |
| `v1.2.0` | `266e990` | Synchronous rating fix, systemd wired to production path |
| `v1.0` | `ba1b1b2` | First live release — DataHub HTTP API, clean 3-card plugin UI |
| `ui-pre-redesign-v2` | `1ca6d20` | Before second UI redesign |
| `architecture-pre-audit` | `2d8e5a2` | Before architecture audit (DataHub decoupling) |
