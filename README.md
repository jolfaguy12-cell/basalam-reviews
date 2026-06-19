# Behdashtik Basalam Sync — v1.4.2

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
                     │ max 1 crawl per 24h (CRAWL_INTERVAL_HOURS)
                     ▼
┌─────────────────────────────────────────────────────────┐
│                   SERVER 2 (Ubuntu)                     │
│                                                         │
│  backend/                                               │
│  ├── crawler.py        fetch all reviews & replies      │
│  ├── database.py       SQLite — dedup, sync, crawl state│
│  ├── datahub_client.py resolve Basalam → WC product IDs │
│  ├── sync.py           crawl → match → push pipeline    │
│  ├── scheduler.py      APScheduler, runs every 6 hours  │
│  ├── log_server.py     HTTP log server (port 8101)       │
│  └── main.py           CLI entry point                  │
│                                                         │
│  Data Hub API (mainhub.behdashtik.ir — read-only)       │
│  └── GET /api/v1/mapping/basalam/{id}  product mapping  │
│                                                         │
│  Log Server (port 8101)                                 │
│  ├── POST   /logs          — receive event from plugin  │
│  ├── GET    /logs?lines=N  — tail of plugin.log         │
│  ├── DELETE /logs          — clear plugin.log           │
│  ├── POST   /sync          — trigger incremental sync   │
│  └── GET    /status        — env, crawl state, DB stats │
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
│  ├── Block star-only reviews if import_star_only=false  │
│  ├── Insert seller replies as child comments            │
│  ├── Apply display settings (prefix, suffix, names)     │
│  ├── Recalculate WooCommerce rating synchronously       │
│  ├── Sync new replies for already-imported reviews      │
│  ├── Push log events to backend log server              │
│  └── Proxy log viewer / sync trigger to log server      │
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

## Hub Architecture Note

Two hub services run on Server 2:

| Service | Endpoint | Used by review system? |
|---------|----------|------------------------|
| `behdashtik-hub-main` | `mainhub.behdashtik.ir` → port 8090 | **Yes** — read-only product ID lookup |
| `wordpress-data-hub` | separate service | **No** — not used in any review code path |

The review backend uses `behdashtik-hub-main` exclusively for read-only `basalam_product_id → wc_product_id` resolution. Both DEV and PRODUCTION WordPress sites share the same product catalog, so reading from the single hub is correct for both environments. No write-path data mixing occurs.

DEV vs PRODUCTION data separation is maintained at all other layers: separate SQLite databases, separate log files, and separate WordPress endpoints.

---

## Responsibility Split

### WordPress Plugin — Lightweight Connector Only

| Responsibility | Owner |
|----------------|-------|
| Authenticate requests (HMAC-SHA256 + API key) | Plugin |
| Insert reviews into `wp_comments` | Plugin |
| Block star-only reviews (when `import_star_only=false`) | Plugin |
| Apply name prefix / suffix | Plugin |
| Randomize seller reply author name | Plugin |
| Attach product thumbnail to review | Plugin |
| Duplicate detection (`basalam_review_id` meta) | Plugin |
| Recalculate WooCommerce rating (synchronous) | Plugin |
| Unapprove star-only reviews (no text) on activation | Plugin |
| Sync new replies for already-imported reviews | Plugin |
| Push log events to backend (fire-and-forget) | Plugin |
| Proxy log viewer / sync trigger to backend | Plugin |
| Product ID matching / mapping logic | Server 2 only |
| DataHub integration | Server 2 only |
| Crawling Basalam API | Server 2 only |
| Crawl rate limiting (`CRAWL_INTERVAL_HOURS`) | Server 2 only |
| Scheduling / cron / retry logic | Server 2 only |
| Deduplication database | Server 2 only |

### Server 2 — Main Processing Center

| Responsibility | Module |
|----------------|--------|
| Crawl Basalam API (paginated, rate-limited, 429 retry) | `crawler.py` |
| Crawl rate limiting (max once per N hours) | `sync.py` + `database.py` |
| SHA-256 content deduplication | `database.py` |
| Sync state + crawl state tracking | `database.py` |
| Product ID mapping via Data Hub HTTP API | `datahub_client.py` |
| Sign and push reviews to WordPress (HTTPS, retry×3) | `wordpress_client.py` / `sync.py` |
| Orchestrate the full pipeline | `sync.py` |
| Schedule incremental syncs (every 6 hours) | `scheduler.py` |
| Rotating file log (`data/debug.log`) | `main.py` |
| Receive plugin log events + serve `data/plugin.log` | `log_server.py` |
| Trigger on-demand incremental sync via HTTP | `log_server.py` |
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

`APP_ENV` selects credentials and auto-derives file paths. Two environments are fully isolated — no shared DB or log files.

```env
APP_ENV=dev       # or "production"

# Dev WordPress credentials (active when APP_ENV=dev)
DEV_WORDPRESS_ENDPOINT=https://dev.example.com
DEV_WORDPRESS_API_KEY=<from plugin settings on dev site>
DEV_WORDPRESS_PLUGIN_SECRET=<from plugin settings on dev site>

# Production WordPress credentials (active when APP_ENV=production)
PROD_WORDPRESS_ENDPOINT=https://behdashtik.ir
PROD_WORDPRESS_API_KEY=<from plugin settings on production site>
PROD_WORDPRESS_PLUGIN_SECRET=<from plugin settings on production site>

# Data Hub HTTP API (shared — same for dev and production)
DATA_HUB_ENDPOINT=https://mainhub.behdashtik.ir
DATA_HUB_API_KEY=<from data hub config>

# Basalam (shared)
BASALAM_ENDPOINT=https://services.basalam.com
BASALAM_VENDOR_ID=1399163
BASALAM_VENDOR_IDENTIFIER=behdashtik

# Service
SERVICE_PORT=8100
CRAWL_PAGE_LIMIT=20
CRAWL_DELAY_SECONDS=2.0
SYNC_INTERVAL_MINUTES=360
CRAWL_INTERVAL_HOURS=24        # Min hours between Basalam crawls (0 = always crawl)
BLOCK_STAR_ONLY_REVIEWS=false  # true = skip star-only reviews before pushing to WordPress
LOG_SERVER_PORT=8101
```

**`CRAWL_INTERVAL_HOURS`**: Prevents more than one Basalam crawl per N hours. The scheduler runs every 6 hours but only crawls Basalam if the interval has elapsed — otherwise it only pushes already-queued reviews to WordPress. Set to `0` to always crawl. The `full-sync` CLI command always crawls regardless of this setting.

**`BLOCK_STAR_ONLY_REVIEWS`**: When `true`, star-only reviews (no text content) are skipped by the backend before pushing to WordPress. Independent of the plugin's `import_star_only` setting — both can be active simultaneously.

**Auto-derived paths** (do not set manually):

| APP_ENV | DB | Backend log | Plugin log |
|---------|-----|------------|-----------|
| `dev` | `data/reviews_dev.db` | `data/debug_dev.log` | `data/plugin_dev.log` |
| `production` | `data/reviews_prod.db` | `data/debug_prod.log` | `data/plugin_prod.log` |

### CLI commands

```bash
.venv/bin/python -m app.main full-sync      # crawl all reviews and push to WordPress (ignores crawl interval)
.venv/bin/python -m app.main sync           # incremental sync (crawls only if 24h+ elapsed)
.venv/bin/python -m app.main push-only      # push pending DB reviews to WordPress; no Basalam crawl
.venv/bin/python -m app.main worker         # start continuous scheduler + log server (blocks)
.venv/bin/python -m app.main status         # DB stats + connection health + crawl state
.venv/bin/python -m app.main fetch-mappings # pull all product mappings from Data Hub
```

**`push-only`**: Pushes all unsynced reviews already in the backend database to WordPress without contacting Basalam. Useful after a manual database import, or when you want to push queued reviews without triggering a new crawl.

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
Upload `releases/basalam-review-plugin-v1.4.3.zip` via **WP Admin → Plugins → Add New → Upload Plugin**.

**Configure:**
1. Go to **Settings → Basalam Review**
2. Click **↻ Generate** next to API Key
3. Click **↻ Generate** next to Plugin Secret
4. Save Settings
5. Copy both values into `backend/.env` on Server 2

**Plugin settings — cards:**

| Card | Contents |
|------|---------|
| Authentication | API Key, Plugin Secret, Environment (DEV/STAGING/PRODUCTION), Regenerate Both |
| Review Display | Name Prefix, Name Suffix, Auto-approve, Attach product image, **Import star-only reviews** toggle |
| Seller Replies | Randomize name, Name pool |
| Debug Logs | Enable toggle, Log Server URL, Log API Key, **Check Backend Connection** button |
| Log Viewer | View Logs, Clear Logs buttons + scrollable output |
| **Sync Status** | Live backend state table (env, star-only policy, DB totals, crawl times, last run, last error) — populated by Check Backend Connection |
| Maintenance | Sync Missed Reviews, Unapprove star-only, Trash star-only, Fix Visibility, **Remove Duplicate Replies**, **Refresh Ratings**, **⚠ Trash All Imported Reviews** |

The **Environment** dropdown labels this WordPress site (`DEV`, `STAGING`, or `PRODUCTION`). A colored badge appears in the admin header, and all maintenance confirmations include the environment name to prevent accidental production operations.

The **Check Backend Connection** button calls `GET /status` on the backend, verifies the connection is live, and populates the **Sync Status** card with live crawl state, database counts, and the last error (if any).

The **Import star-only reviews** toggle controls whether star-only reviews (no text content) are accepted by the plugin on insert. When off, the backend's `BLOCK_STAR_ONLY_REVIEWS=true` setting provides an earlier enforcement layer.

**REST endpoints:**

| Method | Path | Auth |
|--------|------|------|
| `GET` | `/wp-json/basalam-review/v1/health` | None (public) |
| `POST` | `/wp-json/basalam-review/v1/receive` | API Key + HMAC-SHA256 |

---

## Maintenance Actions

All maintenance actions use a **dryrun → preview → confirm → execute** flow. Every action is scoped strictly to plugin-owned reviews (identified by `basalam_review_id` or `basalam_is_reply` commentmeta). Manual WooCommerce reviews are never touched.

| Action | Dryrun output | Execute effect |
|--------|--------------|----------------|
| Sync Missed Reviews | — | Triggers backend push of queued reviews |
| Unapprove Star-only | Count of approved star-only reviews | Sets `comment_approved=0` |
| Trash Star-only | Approved / pending / already-trashed / affected products | Moves to Trash, recalculates ratings |
| Fix Visibility (Migrate Emails) | Count without placeholder email | Sets `basalam-import@noreply.local` |
| **Remove Duplicate Replies** | Count of orphan replies (no `basalam_answer_id` meta) | **Permanently deletes** orphan replies (unrecoverable) |
| **Refresh Ratings** | Product count + active review count | Recalculates WC average rating, count, distribution |
| **⚠ Trash All Imported Reviews** | Root reviews / replies / products | Moves ALL active imported reviews + replies to Trash, recalculates ratings |
| **Clear Synced to WordPress** | Count of synced reviews in backend DB | Resets backend sync state so all reviews can be re-imported; then click "Sync Missed Reviews" |

**Remove Duplicate Replies** is the only action that permanently deletes comments. It targets replies that have `basalam_is_reply` meta but no `basalam_answer_id` meta — these are untrackable orphans from the initial sync that would accumulate duplicates on every future sync. Expected count on first run: ~91.

**Trash All Imported Reviews** is recoverable via **WP Admin → Comments → Trash**. It does not touch the backend database (`wc_comment_id` mapping is preserved, allowing re-push after restore).

---

## Debug Log Viewer

The WordPress plugin pushes key events (review inserted, failed, reply added, etc.) to the backend log server, which stores them in `data/plugin.log`. The admin panel fetches and displays these logs without SSH access.

**Log files:**

| File | Contents |
|------|---------|
| `data/debug.log` | Backend service logs (rotating, 500 KB × 3) |
| `data/plugin.log` | Plugin-pushed events (append-only, cleared via admin UI) |

**Setup:**
1. In plugin settings → **Debug Logs**: enter `https://hub.behdashtik.ir/brp` as Log Server URL
2. Enter the `WORDPRESS_API_KEY` value as Log API Key (or leave blank — it defaults to the API Key from Card 1)
3. Save Settings → click **View Logs**

No firewall changes needed — the log server is proxied through the existing `hub.behdashtik.ir` nginx + SSL setup (`/brp/` → `localhost:8101`).

**Log server endpoints** (all require `X-BRP-API-Key` header, served via `https://hub.behdashtik.ir/brp`):

```
GET    /brp/status            — env, DB stats, crawl state, last run, last error
POST   /brp/logs              — plugin pushes a JSON log event; appended to plugin_{env}.log
GET    /brp/logs?lines=200    — returns last N lines of plugin_{env}.log
DELETE /brp/logs              — clears plugin_{env}.log
POST   /brp/sync              — triggers an incremental sync immediately (non-blocking)
POST   /brp/push-only         — push queued DB reviews to WordPress; no Basalam crawl (synchronous, batch≤50)
POST   /brp/reset-sync        — clear wc_comment_id for all reviews so they re-enter the push queue (used by "Clear Synced to WordPress" button)
```

**Sync Missed Reviews note:** Each `/push-only` call processes up to 50 unsynced reviews. For large backlogs click the button multiple times until it shows `Inserted: 0`.

**`/status` response fields:**
```json
{
  "env": "DEV",
  "db_path": "data/reviews_dev.db",
  "wordpress": "https://dev.behdashtik.ir",
  "block_star_only": false,
  "db": {
    "total_reviews": 410,
    "synced": 396,
    "unsynced": 14,
    "last_crawled_at": "2026-06-19T20:51:00",
    "next_crawl_allowed_at": "2026-06-20T20:51:00",
    "crawl_interval_hours": 24,
    "last_run": {"run_at": "...", "mode": "incremental", "inserted": 0, "errors": 0},
    "recent_runs": [...],
    "last_error": null
  }
}
```

**Plugin log event format:**
```
[2026-06-19 12:34:56] [DEV] INFO review_inserted | {"basalam_review_id": 12345, "wc_comment_id": 456}
[2026-06-19 12:34:57] [DEV] ERROR insert_failed | {"basalam_review_id": 12346, "wc_product_id": 789}
[2026-06-19 12:34:58] INFO duplicate_found | {"basalam_review_id": 12345, "wc_comment_id": 456}
[2026-06-19 12:34:59] INFO replies_added | {"parent_wc_comment_id": 456, "count": 1}
[2026-06-19 12:35:00] INFO star_only_blocked | {"basalam_review_id": 12347}
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
| Maintenance scoping | All bulk operations JOIN on `basalam_review_id` or `basalam_is_reply` meta |

---

## Data Flow

1. **Crawl** — Server 2 fetches all reviews from Basalam API (20/page, rate-limited, 429 → 60s wait + retry). Skipped if less than `CRAWL_INTERVAL_HOURS` have passed since last crawl.
2. **Dedup** — SHA-256 hash detects new or changed reviews; SQLite stores state. If a synced review's hash changes (new reply), `wc_comment_id` is cleared so it re-enters the push queue
3. **Match** — Server 2 resolves Basalam product IDs → WooCommerce IDs via Data Hub API
4. **Filter** — Star-only reviews skipped if `BLOCK_STAR_ONLY_REVIEWS=true` (backend) or `import_star_only=false` (plugin)
5. **Push** — Server 2 POSTs each review (including `basalam_answer_id` per reply) to the plugin over HTTPS (HMAC-signed, retries up to 3×)
6. **Insert** — Plugin verifies auth; if review is new, inserts into `wp_comments`; if existing, processes only new replies (idempotent via `basalam_answer_id` commentmeta). Replies with `basalam_answer_id=0` are skipped to prevent orphan accumulation.
7. **Reply** — Seller replies inserted as child comments; `basalam_answer_id` stored in commentmeta; WC hook wrapped in try/finally
8. **Recalc** — Plugin recalculates WooCommerce product average rating synchronously via `WC_Comments`
9. **Log** — Server 2 records sync result in SQLite; plugin pushes key events to `data/plugin.log` via fire-and-forget POST

---

## Production Deployment Checklist

- [x] Plugin installed on `behdashtik.ir` (upgrade to v1.4.3 available in releases/)
- [x] Data Hub connected via HTTP API (`https://mainhub.behdashtik.ir`, 346 mappings)
- [x] Auto-sync running every 6 hours via systemd (`basalam-review.service`)
- [x] HTTPS verified (all WordPress traffic encrypted)
- [x] API key + secret configured in `backend/.env`
- [x] `WORDPRESS_ENDPOINT=https://behdashtik.ir` set in production `.env`
- [x] Rotating file log enabled (`data/debug.log`, 500 KB × 3)
- [x] Log server running on port 8101 alongside scheduler
- [x] Log server proxied via nginx at `https://hub.behdashtik.ir/brp` (no firewall exposure needed)
- [ ] Configure Log Server URL in plugin settings: `https://hub.behdashtik.ir/brp`
- [ ] Run "Remove Duplicate Replies" maintenance action (dryrun → expect ~91, then execute)
- [ ] Set `CRAWL_INTERVAL_HOURS=24` in production `.env` (prevents Basalam IP ban)

---

## Rollback Tags

| Tag | Commit | Description |
|-----|--------|-------------|
| `v1.4.3` | _(current)_ | "Clear Synced to WordPress" button + backend /reset-sync endpoint; policy-block sentinel (-1) prevents star-only reviews from clogging retry queue when import_star_only=false |
| `v1.4.2` | `d2c8245` | Backend: push-only HTTP batch capped at 50 (nginx timeout fix); backend DB stale-sync reconciliation |
| `v1.4.1` | `fd0f052` | Plugin: WC ratings fatal fix (WC 10.7 compat), settings merge fix, `/push-only` HTTP endpoint with real result counts |
| `v1.4.0` | `6147037` | Crawl rate limit, push-only CLI, Sync Status card, Remove Duplicate Replies, Refresh Ratings, Trash All, star-only policy, wider star-only detection |
| `v1.3.0` | — | Visibility fix, batch rating recalc, trash/migrate maintenance actions |
| `v1.2.5` | `7756161` | Logging on/off toggle |
| `v1.2.4` | `f112533` | Plugin-push logs, manual sync trigger, new reply detection and sync |
| `v1.2.3` | `26516ce` | Debug log viewer, star-only unapproval, hard-mode resilience fixes |
| `v1.2.0` | `266e990` | Synchronous rating fix, systemd wired to production path |
| `v1.0` | `ba1b1b2` | First live release — DataHub HTTP API, clean 3-card plugin UI |
| `ui-pre-redesign-v2` | `1ca6d20` | Before second UI redesign |
| `architecture-pre-audit` | `2d8e5a2` | Before architecture audit (DataHub decoupling) |
