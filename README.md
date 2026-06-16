# Behdashtik Basalam Sync — v1.0

Continuously syncs Basalam marketplace reviews into WooCommerce.
Two-component system: a Python backend service on Server 2 and a lightweight WordPress plugin on the site.

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
│  Data Hub API (local, port 8089)                        │
│  └── GET /api/v1/mapping/basalam/{id}  product mapping  │
│                                                         │
│  ALL heavy processing: mapping, dedup, scheduling,      │
│  retry logic, DataHub integration, audit logs           │
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
│  └── Trigger WooCommerce rating recalculation           │
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

## Folder Layout

| Path | Purpose |
|------|---------|
| `/root/basalam-review/` | **Development & debug** — active development happens here |
| `/root/behdashtik-basalam-sync/` | **Production copy** — deploy from here to live site |

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
| Trigger WooCommerce rating recalculation | Plugin |
| Product ID matching / mapping logic | Server 2 only |
| DataHub integration | Server 2 only |
| Crawling Basalam API | Server 2 only |
| Scheduling / cron / retry logic | Server 2 only |
| Deduplication database | Server 2 only |

### Server 2 — Main Processing Center

| Responsibility | Module |
|----------------|--------|
| Crawl Basalam API (paginated, rate-limited) | `crawler.py` |
| SHA-256 content deduplication | `database.py` |
| Sync state tracking | `database.py` |
| Product ID mapping via Data Hub HTTP API | `datahub_client.py` |
| Sign and push reviews to WordPress (HTTPS) | `wordpress_client.py` |
| Orchestrate the full pipeline | `sync.py` |
| Schedule incremental syncs (every 30 min) | `scheduler.py` |
| CLI commands for manual operations | `main.py` |

---

## Backend Setup

```bash
cd backend
pip install -r requirements.txt
cp .env.example .env   # fill in credentials
python -m app.main status
```

### Environment variables

```env
APP_ENV=production

# WordPress plugin credentials (copy from plugin Settings page)
WORDPRESS_ENDPOINT=https://behdashtik.ir
WORDPRESS_API_KEY=<from plugin settings>
WORDPRESS_PLUGIN_SECRET=<from plugin settings>

# Data Hub HTTP API (local on Server 2)
DATA_HUB_ENDPOINT=http://127.0.0.1:8089
DATA_HUB_API_KEY=<from /root/wordpress-data-hub/server2/config.json → data_api.key>

# Basalam
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

### CLI commands

```bash
python -m app.main full-sync        # crawl all reviews and push to WordPress
python -m app.main sync             # incremental sync (new/changed only)
python -m app.main worker           # start continuous scheduler (blocks)
python -m app.main status           # DB stats + connection health
python -m app.main fetch-mappings   # pull all product mappings from Data Hub
```

### Systemd

```bash
cp systemd/basalam-review.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now basalam-review
journalctl -u basalam-review -f
```

Service port: **8100** (configurable via `SERVICE_PORT`)

---

## WordPress Plugin Setup

**Install:**
```bash
# On the WordPress server:
cp -r wordpress-plugin/basalam-review-plugin/ /var/www/<site>/wp-content/plugins/
wp --path=/var/www/<site> plugin activate basalam-review-plugin --allow-root
```
Or upload via WP Admin → Plugins → Add New → Upload Plugin.

**Configure:**
1. Go to **Settings → Basalam Review**
2. Click **↻ Generate** next to API Key
3. Click **↻ Generate** next to Plugin Secret
4. Save Settings
5. Copy both values into `backend/.env` on Server 2

**Plugin settings — three cards:**

| Card | Settings |
|------|---------|
| Authentication | API Key, Plugin Secret, Regenerate Both |
| Review Display | Name Prefix, Name Suffix, Auto-approve, Attach product image |
| Seller Replies | Randomize name, Name pool |

**REST endpoints:**

| Method | Path | Auth |
|--------|------|------|
| `GET` | `/wp-json/basalam-review/v1/health` | None (public) |
| `POST` | `/wp-json/basalam-review/v1/receive` | API Key + HMAC-SHA256 |

---

## Data Hub Integration

The Data Hub runs locally on Server 2 at `http://127.0.0.1:8089`. The backend connects to it via HTTP API using `X-Hub-API-Key` authentication.

**Mapping endpoints used:**

```
GET /api/v1/mapping/basalam/{basalam_product_id}?vendor_id=1399163
    → {"data": {"basalam_product_id": ..., "wc_product_id": ...}}

GET /api/v1/mapping/basalam?vendor_id=1399163
    → {"data": {"count": N, "mappings": [...]}}
```

API key is in `/root/wordpress-data-hub/server2/config.json` under `data_api.key`.

---

## Security

| Layer | Mechanism |
|-------|-----------|
| Transport | HTTPS — all Server 2 → WordPress traffic is SSL-encrypted |
| Request authentication | `X-BRP-API-Key` header, verified with `hash_equals()` |
| Request integrity | `X-BRP-Signature: sha256=HMAC(secret, body)` |
| Duplicate prevention | `basalam_review_id` meta checked before insert |
| No secrets in code | All credentials in `.env` (gitignored) or WP options |
| Plugin receive-only | Plugin never initiates outbound connections |

---

## Data Flow

1. **Crawl** — Server 2 fetches all reviews from Basalam API (20/page, rate-limited)
2. **Dedup** — SHA-256 hash detects new or changed reviews; SQLite stores state
3. **Match** — Server 2 resolves Basalam product IDs → WooCommerce IDs via Data Hub API
4. **Push** — Server 2 POSTs each review to the plugin over HTTPS (HMAC-signed)
5. **Insert** — Plugin verifies auth, checks duplicates, inserts into `wp_comments`
6. **Reply** — Seller replies inserted as child comments
7. **Recalc** — Plugin queues WooCommerce product rating recalculation
8. **Log** — Server 2 records sync result in SQLite

---

## Production Deployment Checklist

- [x] Plugin tested on `dev.behdashtik.ir`
- [x] Data Hub connected via HTTP API (346 mappings available)
- [x] Auto-sync running every 30 min via systemd
- [x] HTTPS verified (all WordPress traffic encrypted)
- [ ] Plugin installed on `behdashtik.ir`
- [ ] New API key + secret generated for production
- [ ] `APP_ENV=production` and `WORDPRESS_ENDPOINT=https://behdashtik.ir` set in `/root/behdashtik-basalam-sync/backend/.env`
- [ ] `systemctl enable --now behdashtik-basalam-sync` (production service)
- [ ] `python -m app.main full-sync` run against production site

---

## Rollback Tags

| Tag | Commit | Description |
|-----|--------|-------------|
| `v1.0` | current | First live release — DataHub HTTP API, clean 3-card plugin UI |
| `ui-pre-redesign-v2` | `1ca6d20` | Before second UI redesign |
| `architecture-pre-audit` | `2d8e5a2` | Before architecture audit (DataHub decoupling) |
