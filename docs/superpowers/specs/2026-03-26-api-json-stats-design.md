# Design: JSON API for Dashboard Statistics

**Date:** 2026-03-26

## Overview

Add a single read-only JSON endpoint to the analytics system that exposes the same statistics shown on the dashboard. Access is controlled by a hardcoded API key defined in the existing config file.

## Endpoint

```
GET /analytics/api.php?api_key=<key>
```

- New file: `analytics/api.php`
- No new directories required
- Mirrors the placement of the existing `analytics/track.php`

## Authentication

A new constant `API_KEY` is added to `analytics/includes/config.php`:

```php
const API_KEY = '';  // Set to a long random string to enable; empty = disabled
```

Auth logic in `analytics/api.php`:

- Read `$_GET['api_key']`
- If `API_KEY === ''` (default) → 401, API disabled
- If key does not match → 401, using `hash_equals()` for constant-time comparison
- No session, no CSRF — GET-only, no state mutation

## Response Shape

HTTP 200 with `Content-Type: application/json`:

```json
{
  "pageviews_by_month": [
    {"month": "2026-03", "pageviews": 142, "unique_visitors": 58}
  ],
  "link_clicks_by_month": [
    {"month": "2026-03", "event_name": "nav-home", "clicks": 12}
  ],
  "last_events": [
    {
      "created_at": "2026-03-26 10:00:00",
      "site": "example.com",
      "event_type": "pageview",
      "event_name": null,
      "page_url": "/"
    }
  ]
}
```

### Dataset details

| Key | Source query | Order |
|-----|-------------|-------|
| `pageviews_by_month` | `events WHERE event_type = 'pageview'` grouped by `strftime('%Y-%m', created_at)` | month DESC |
| `link_clicks_by_month` | `events WHERE event_type = 'link_click'` grouped by month + event_name | month DESC, clicks DESC |
| `last_events` | all event types, `LIMIT 50` | id DESC |

- No site filter — returns data across all sites (the analytics script is intended for a single domain).
- No pagination — same 50-event limit as the dashboard.

## Error Responses

```json
{"error": "Unauthorized"}   // HTTP 401 — missing, wrong, or empty API key
```

## Files Changed

| File | Change |
|------|--------|
| `analytics/includes/config.php` | Add `API_KEY` constant (default empty string) |
| `analytics/api.php` | New file — auth check + three queries + `json_response()` |

No changes to `auth.php`, `db.php`, `helpers.php`, or dashboard files.

## Security Notes

- `hash_equals()` prevents timing attacks on key comparison.
- Empty default value means the API is disabled until explicitly configured.
- Read-only queries only — no write operations.
- Existing `json_response()` helper in `helpers.php` is reused.
