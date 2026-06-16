# Basalam Review Plugin

A two-component system that continuously syncs Basalam marketplace reviews into WooCommerce.

---

## Architecture

```
Basalam API
    │
    ▼
backend/          ← Ubuntu server (Python)
    │  HMAC-signed POST
    ▼
WordPress Plugin  ← Shared hosting (PHP)
    │
    ▼
WooCommerce Reviews (wp_comments)
    │
    ▲
Data Hub          ← Product ID matching
```

---

## Components

### 1. Backend Service (`backend/`)

Python service running on the Ubuntu server.

| Module | Purpose |
|--------|---------|
| `app/crawler.py` | Paginates Basalam API to fetch all reviews |
| `app/database.py` | SQLite — upsert with SHA-256 dedup, tracks sync state |
| `app/datahub_client.py` | Resolves Basalam product IDs → WooCommerce product IDs |
| `app/wordpress_client.py` | HMAC-signed POST to WordPress plugin |
| `app/sync.py` | Orchestrates crawl → match → push pipeline |
| `app/scheduler.py` | APScheduler cron (default: every 30 min) |
| `app/main.py` | CLI entry point |

**Setup:**

```bash
cd backend
pip install -r requirements.txt
cp .env.example .env   # fill in credentials
python -m app.main status
```

**Commands:**

```bash
python -m app.main full-sync       # crawl all reviews and push to WordPress
python -m app.main worker          # start continuous scheduler (blocks)
python -m app.main status          # DB stats + service health
python -m app.main fetch-mappings  # pull product mappings from Data Hub
```

**Systemd:**

```bash
cp systemd/basalam-review.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now basalam-review
```

**Active ports on this server (do not reuse):**
`22, 80, 443, 3000, 3306, 3308, 3610, 5000, 6363, 8080, 8089, 8090, 9000, 19877, 30303`

Default service port: **8100** (configurable via `SERVICE_PORT` in `.env`)

---

### 2. WordPress Plugin (`wordpress-plugin/`)

PHP plugin installed on the WooCommerce site.

**REST endpoints:**

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| `GET` | `/wp-json/basalam-review/v1/health` | Public | Health check |
| `POST` | `/wp-json/basalam-review/v1/receive` | API Key + HMAC | Receive a review |

**Plugin settings (Settings → Basalam Review):**

| Setting | Description |
|---------|-------------|
| API Key | Must match `WORDPRESS_API_KEY` in backend `.env` |
| Plugin Secret | Must match `WORDPRESS_PLUGIN_SECRET` in backend `.env` |
| Customer Name Prefix / Suffix | Prepend / append text to reviewer name |
| Admin Name Randomizer | Randomly pick seller reply author from a pool |
| Admin Name Pool | Newline-separated list of names to randomize from |
| Attach Product Image | Attach WC product thumbnail to the review |
| Auto-approve Reviews | Skip moderation queue |

**Install:**

Upload `wordpress-plugin/basalam-review-plugin/` to `wp-content/plugins/` and activate.

---

## Environment Variables

```env
APP_ENV=development

WORDPRESS_ENDPOINT=https://dev.behdashtik.ir
WORDPRESS_API_KEY=
WORDPRESS_PLUGIN_SECRET=

DATA_HUB_ENDPOINT=
DATA_HUB_API_KEY=

BASALAM_ENDPOINT=https://services.basalam.com
BASALAM_VENDOR_ID=1399163
BASALAM_VENDOR_IDENTIFIER=behdashtik

INTERNAL_DB_PATH=data/reviews.db
SERVICE_PORT=8100
SYNC_INTERVAL_MINUTES=30
```

> Development mode targets `dev.behdashtik.ir` and the internal Data Hub only.
> Production endpoints must not be configured until the development version is validated.

---

## Data Flow

1. **Crawl** — backend fetches all reviews from `services.basalam.com` (20/page)
2. **Dedup** — SHA-256 hash detects new or changed reviews; SQLite stores state
3. **Match** — Basalam product IDs resolved to WooCommerce IDs via Data Hub
4. **Push** — backend POSTs each unsynced review to the WordPress plugin (HMAC-signed)
5. **Insert** — plugin inserts into `wp_comments` with `comment_type=review` and stores `basalam_review_id` meta to prevent duplicates
6. **Replies** — seller replies inserted as child comments; admin name optionally randomized

---

## Security

- All backend→WordPress requests are signed with HMAC-SHA256 (`X-BRP-Signature`)
- Plugin validates both API key and signature before inserting anything
- No credentials or secrets in source code — environment variables only
- Duplicate protection: plugin checks `basalam_review_id` comment meta before inserting
