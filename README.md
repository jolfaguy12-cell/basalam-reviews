# Basalam Review Scraper

Fetches all customer reviews (and seller replies) for a Basalam vendor and exports them to CSV and Excel.

## How it works

Basalam's frontend is a Next.js SPA that loads reviews dynamically from `https://services.basalam.com`. This tool calls that API directly, paginating through 20-reviews-per-request responses until all reviews are collected.

## Usage

```bash
pip install openpyxl
python fetch_reviews.py
```

Output files (gitignored):
- `behdashtik_reviews.csv` — UTF-8 CSV, Excel-compatible
- `behdashtik_reviews.xlsx` — styled spreadsheet with color-coded star ratings

## Columns

| Column | Description |
|--------|-------------|
| review_id | Unique review ID |
| date | Review date (YYYY-MM-DD) |
| user_name | Reviewer's name |
| stars | Rating (1–5) |
| review | Review text |
| product_id | Basalam product ID |
| product | Product title |
| reply_by | Seller name (if replied) |
| reply | Seller reply text |

## API

```
GET https://services.basalam.com/web/v1/review/vendor/{vendor_id}/reviews?limit=20&offset=0
GET https://services.basalam.com/web/v1/review/vendor/{vendor_id}/reviews/group
```

Vendor ID for `behdashtik`: `1399163`
