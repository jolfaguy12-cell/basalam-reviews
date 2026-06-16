# Basalam Review Connector

A two-component system that continuously syncs Basalam marketplace reviews into WooCommerce.

---

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│                    Basalam API                          │
│           services.basalam.com                          │
└────────────────────┬────────────────────────────────────┘
                     │ crawl (paged, rate-limited)
                     ▼
┌─────────────────────────────────────────────────────────┐
│                   SERVER 2 (Ubuntu)                     │
│                                                         │
│  backend/                                               │
│  ├── crawler.py        fetch all reviews & replies      │
│  ├── database.py       SQLite — dedup, sync state       │
│  ├── datahub_client.py resolve Basalam → WC product IDs │
│  ├── sync.py           crawl → match → push pipeline    │
│  ├── scheduler.py      APScheduler, runs every 30 min   │
│  └── main.py           CLI entry point                  │
│                                                         │
│  ALL heavy processing: mapping, dedup, scheduling,      │
│  retry logic, DataHub integration, audit logs           │
└────────────────────┬────────────────────────────────────┘
                     │ HMAC-signed POST /wp-json/…/receive
                     │ (pre-processed payload, ready to insert)
                     ▼
┌─────────────────────────────────────────────────────────┐
│           WORDPRESS PLUGIN (shared hosting)             │
│                                                         │
│  Thin, secure connector only:                           │
│  ├── Authenticate incoming requests (HMAC + API key)    │
│  ├── Insert pre-processed reviews into wp_comments      │
│  ├── Insert seller replies as child comments            │
│  ├── Apply display settings (prefix, suffix, names)     │
│  └── Return wp_comment_id to Server 2                   │
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

The plugin acts as a **secure transport endpoint**. It must not perform analysis, mapping, or any heavy processing.

| Responsibility | Status |
|----------------|--------|
| Authenticate incoming requests (HMAC-SHA256 + API key) | ✅ Plugin |
| Insert reviews into `wp_comments` | ✅ Plugin |
| Apply name prefix / suffix | ✅ Plugin (display setting) |
| Randomize seller reply author name | ✅ Plugin (display setting) |
| Attach product thumbnail to review | ✅ Plugin (display setting) |
| Duplicate detection (via `basalam_review_id` meta) | ✅ Plugin |
| Trigger WooCommerce rating recalculation | ✅ Plugin |
| Product ID matching / mapping logic | ❌ Server 2 only |
| DataHub integration | ❌ Server 2 only |
| Crawling Basalam API | ❌ Server 2 only |
| Scheduling / cron / retry logic | ❌ Server 2 only |
| Deduplication database | ❌ Server 2 only |
| Sync state management | ❌ Server 2 only |
| AI processing or business logic | ❌ Server 2 only |

### Server 2 — Main Processing Center

| Responsibility | Module |
|----------------|--------|
| Crawl Basalam API (paginated, rate-limited) | `crawler.py` |
| SHA-256 content deduplication | `database.py` |
| Sync state tracking (unsynced, synced) | `database.py` |
| Product ID matching via Data Hub | `datahub_client.py` |
| Sign and push reviews to WordPress | `wordpress_client.py` |
| Orchestrate the full pipeline | `sync.py` |
| Schedule incremental syncs (every 30 min) | `scheduler.py` |
| CLI commands for manual operations | `main.py` |

---

## Components

### 1. Backend Service (`backend/`)

Python service running as a systemd unit on Server 2.

**Setup:**

```bash
cd backend
pip install -r requirements.txt
cp .env.example .env   # fill in credentials
python -m app.main status
```

**Environment variables (`backend/.env`):**

```env
APP_ENV=development

# WordPress connector credentials (must match the plugin settings page)
WORDPRESS_ENDPOINT=https://dev.behdashtik.ir
WORDPRESS_API_KEY=<generate on plugin settings page>
WORDPRESS_PLUGIN_SECRET=<generate on plugin settings page>

# Data Hub — configured here, never in the WordPress plugin
DATA_HUB_ENDPOINT=
DATA_HUB_API_KEY=

# Basalam API
BASALAM_ENDPOINT=https://services.basalam.com
BASALAM_VENDOR_ID=1399163
BASALAM_VENDOR_IDENTIFIER=behdashtik

# Runtime
INTERNAL_DB_PATH=data/reviews.db
SERVICE_PORT=8100
CRAWL_PAGE_LIMIT=20
CRAWL_DELAY_SECONDS=0.5
SYNC_INTERVAL_MINUTES=30
```

**CLI commands:**

```bash
python -m app.main full-sync        # crawl all reviews and push to WordPress
python -m app.main sync             # incremental sync (new/changed only)
python -m app.main worker           # start continuous scheduler (blocks)
python -m app.main status           # DB stats + connection health
python -m app.main fetch-mappings   # pull product mappings from Data Hub
```

**Systemd:**

```bash
cp systemd/basalam-review.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now basalam-review
journalctl -u basalam-review -f    # follow logs
```

**Occupied ports (do not reuse):**
`22, 80, 443, 3000, 3306, 3308, 3610, 5000, 6363, 8080, 8089, 8090, 9000, 19877, 30303`

Default service port: **8100** (configurable via `SERVICE_PORT`)

---

### 2. WordPress Plugin (`wordpress-plugin/`)

PHP plugin installed on the WooCommerce site. Acts as a thin, authenticated insertion endpoint.

**REST API:**

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| `GET` | `/wp-json/basalam-review/v1/health` | None | Health check |
| `POST` | `/wp-json/basalam-review/v1/receive` | API Key + HMAC | Insert a review |

**Authentication model:**

Every `POST /receive` request from Server 2 must include:
- `X-BRP-API-Key: <key>` — matches `api_key` in plugin settings
- `X-BRP-Signature: sha256=<hex>` — `HMAC-SHA256(plugin_secret, request_body)`

The plugin rejects requests that fail either check.

**Plugin settings page (Settings → Basalam Review):**

| Setting | Purpose |
|---------|---------|
| API Key | Auth token; copy to `WORDPRESS_API_KEY` in Server 2 `.env` |
| Plugin Secret | HMAC signing key; copy to `WORDPRESS_PLUGIN_SECRET` in Server 2 `.env` |
| Customer Name Prefix / Suffix | Prepend / append text to reviewer display name |
| Auto-approve Reviews | Skip WordPress moderation queue |
| Attach Product Image | Attach WC product thumbnail to each review |
| Randomize Admin Name | Pick seller reply author from a pool |
| Admin Name Pool | Newline-separated list of names |

**Install:**

```bash
# On same server as WordPress:
cp -r wordpress-plugin/basalam-review-plugin/ /var/www/<site>/wp-content/plugins/
wp --path=/var/www/<site> plugin activate basalam-review-plugin --allow-root
```

Or upload via WP Admin → Plugins → Add New → Upload.

---

## Configuration Steps

### Step 1 — Install the plugin

Upload and activate `basalam-review-plugin` on your WooCommerce site.

### Step 2 — Generate credentials

1. Go to **Settings → Basalam Review**
2. Click **↻ Generate** next to API Key
3. Click **↻ Generate** next to Plugin Secret
4. Click **Save Settings**

### Step 3 — Configure Server 2

Copy the generated values into `/root/basalam-review/backend/.env`:

```env
WORDPRESS_API_KEY=<value from plugin settings>
WORDPRESS_PLUGIN_SECRET=<value from plugin settings>
WORDPRESS_ENDPOINT=https://your-site.com
```

### Step 4 — Run a test sync

```bash
cd /root/basalam-review/backend
python -m app.main full-sync
```

Expected output: reviews inserted into WooCommerce.

### Step 5 — Enable auto-sync

```bash
systemctl enable --now basalam-review
```

---

## Manual Connection Test

**Test the plugin health endpoint:**

```bash
curl https://your-site.com/wp-json/basalam-review/v1/health
# Expected: {"status":"ok","version":"1.0.0","time":"..."}
```

**Test Server 2 connection to WordPress:**

```bash
cd /root/basalam-review/backend
python -m app.main status
# Expected: wordpress_healthy: true
```

**Send a single test review manually:**

```python
import json, hmac, hashlib, urllib.request

API_KEY    = "your-api-key"
SECRET     = "your-plugin-secret"
ENDPOINT   = "https://dev.behdashtik.ir/wp-json/basalam-review/v1/receive"

payload = json.dumps({
    "basalam_review_id": 99999999,
    "wc_product_id": 3442,
    "user_name": "تست کاربر",
    "star": 5,
    "description": "محصول عالی بود",
    "created_at": "2026-01-01 12:00:00",
    "replies": []
}, ensure_ascii=False).encode()

sig = hmac.new(SECRET.encode(), payload, hashlib.sha256).hexdigest()
req = urllib.request.Request(ENDPOINT, data=payload, headers={
    "Content-Type": "application/json",
    "X-BRP-API-Key": API_KEY,
    "X-BRP-Signature": f"sha256={sig}",
})
with urllib.request.urlopen(req) as r:
    print(r.read().decode())
```

---

## Data Hub Integration

The Data Hub is configured **entirely on Server 2** via environment variables. The WordPress plugin has no knowledge of the Data Hub and must never be coupled to it.

```env
# backend/.env on Server 2
DATA_HUB_ENDPOINT=https://your-datahub.example.com
DATA_HUB_API_KEY=your-datahub-api-key
```

When `DATA_HUB_ENDPOINT` is set, Server 2 automatically resolves Basalam product IDs to WooCommerce product IDs before pushing reviews to WordPress.

Without DataHub: only products with manually-inserted mappings in the SQLite `product_mappings` table will sync.

---

## Rollback / Checkpoint

A Git tag marks the state just before the architecture audit:

```bash
# Roll back to the pre-audit checkpoint (both plugin and backend):
git checkout architecture-pre-audit
```

All changes since that tag are in commits after `2d8e5a2`.

---

## Data Flow (step by step)

1. **Crawl** — Server 2 fetches all reviews from `services.basalam.com` (20/page, rate-limited)
2. **Dedup** — SHA-256 hash detects new or changed reviews; SQLite stores state
3. **Match** — Server 2 resolves Basalam product IDs → WooCommerce IDs via Data Hub
4. **Push** — Server 2 POSTs each unsynced, mapped review to the plugin (HMAC-signed)
5. **Insert** — Plugin verifies auth, checks for duplicates, inserts into `wp_comments`
6. **Reply** — Seller replies inserted as child comments
7. **Recalc** — Plugin queues WooCommerce product rating recalculation
8. **Log** — Server 2 records the sync result in SQLite

---

## Security Model

| Layer | Mechanism |
|-------|-----------|
| Request authentication | `X-BRP-API-Key` header, compared with `hash_equals()` |
| Request integrity | `X-BRP-Signature: sha256=HMAC(secret, body)` |
| Duplicate prevention | `basalam_review_id` comment meta checked before insert |
| No credentials in code | All secrets in `.env` (gitignored) or WordPress options |
| Plugin only receives | Plugin never initiates outbound connections |

---

## Performance Safeguards

- Plugin assets (CSS + JS) load **only** on the plugin settings page
- Stats queries use a `JOIN` on the indexed `meta_key` column — lightweight
- No queries run on WordPress frontend, checkout, or WooCommerce product pages
- DataHub and Basalam API calls run on Server 2, never inside WordPress
- WooCommerce rating recalc is queued (not synchronous)
- Sync runs on Server 2's scheduler — zero cron pressure on WordPress

---

## Production Deployment Checklist

- [ ] Plugin tested on dev site (`dev.behdashtik.ir`) ✅
- [ ] `APP_ENV=production` set in backend `.env`
- [ ] `WORDPRESS_ENDPOINT` updated to production domain
- [ ] New API key + secret generated for production
- [ ] Backend `.env` updated with production credentials
- [ ] Systemd service enabled on Server 2
- [ ] `DATA_HUB_ENDPOINT` configured when Data Hub is available
- [ ] SSL verified on production WordPress endpoint
