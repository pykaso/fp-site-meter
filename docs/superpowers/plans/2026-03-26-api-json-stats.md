# JSON Stats API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a single read-only JSON endpoint `analytics/api.php` that returns dashboard statistics (pageviews by month, link clicks by month, last 50 events) gated by a hardcoded API key from config.

**Architecture:** One new entry-point file mirrors the structure of `track.php`. Auth is a constant-time key compare against a new `API_KEY` constant in the existing `config.php`. All three queries are copies of the dashboard queries with the site filter removed.

**Tech Stack:** PHP 8+, SQLite via PDO, no new dependencies.

---

## File Map

| File | Action | Responsibility |
|------|--------|---------------|
| `analytics/includes/config.php` | Modify | Add `API_KEY` constant (default `''` = disabled) |
| `analytics/api.php` | Create | Auth check + 3 SQL queries + JSON response |
| `tests/api_smoke.php` | Create | HTTP smoke test (401 and 200 cases) |

---

## Task 1: Add API_KEY constant to config

**Files:**
- Modify: `analytics/includes/config.php`

- [ ] **Step 1: Add the constant**

Open `analytics/includes/config.php` and append after the `DASH_SESSION_NAME` constant:

```php
/**
 * API key for the JSON stats endpoint.
 * Set to a long random string to enable the API; empty string = disabled.
 */
const API_KEY = '';
```

The file should end like this:

```php
const DASH_SESSION_NAME = 'analytics_dash_sess';

/**
 * API key for the JSON stats endpoint.
 * Set to a long random string to enable the API; empty string = disabled.
 */
const API_KEY = '';
```

- [ ] **Step 2: Commit**

```bash
git add analytics/includes/config.php
git commit -m "feat(config): add API_KEY constant for JSON stats endpoint"
```

---

## Task 2: Write the smoke test

**Files:**
- Create: `tests/api_smoke.php`

The smoke test follows the same pattern as `tests/track_smoke.php`: accepts CLI args, makes HTTP requests, prints PASS/FAIL, exits 0/1. It accepts the base URL and the API key as arguments so it works against any environment without touching config.

- [ ] **Step 1: Create `tests/api_smoke.php`**

```php
<?php

declare(strict_types=1);

$base = $argv[1] ?? '';
$key  = $argv[2] ?? '';

if ($base === '' || $key === '') {
    fwrite(STDERR, "usage: php tests/api_smoke.php <baseUrl> <apiKey>\n");
    exit(1);
}

$base = rtrim($base, '/');
$url  = $base . '/analytics/api.php';

/**
 * @return array{0: int, 1: string}
 */
function get_url(string $url): array
{
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (!empty($http_response_header) && is_array($http_response_header)) {
        $first = $http_response_header[0] ?? '';
        if (preg_match('#HTTP/\S+\s+(\d{3})#', $first, $m)) {
            $code = (int) $m[1];
        }
    }

    return [$code, $response === false ? '' : $response];
}

// Case 1: no key → 401
[$code1, $body1] = get_url($url);
$pass1 = $code1 === 401;

// Case 2: wrong key → 401
[$code2, $body2] = get_url($url . '?api_key=wrong-key');
$pass2 = $code2 === 401;

// Case 3: correct key → 200, valid JSON with expected keys
[$code3, $body3] = get_url($url . '?api_key=' . urlencode($key));
$data3 = json_decode($body3, true);
$pass3 = $code3 === 200
    && is_array($data3)
    && array_key_exists('pageviews_by_month', $data3)
    && array_key_exists('link_clicks_by_month', $data3)
    && array_key_exists('last_events', $data3)
    && is_array($data3['pageviews_by_month'])
    && is_array($data3['link_clicks_by_month'])
    && is_array($data3['last_events']);

if ($pass1 && $pass2 && $pass3) {
    echo "PASS\n";
    exit(0);
}

echo "FAIL\n";
if (!$pass1) {
    echo "  no key: expected 401; got {$code1} " . substr($body1, 0, 200) . "\n";
}
if (!$pass2) {
    echo "  wrong key: expected 401; got {$code2} " . substr($body2, 0, 200) . "\n";
}
if (!$pass3) {
    echo "  valid key: expected 200 with pageviews_by_month/link_clicks_by_month/last_events; got {$code3} " . substr($body3, 0, 200) . "\n";
}
exit(1);
```

- [ ] **Step 2: Commit the test**

```bash
git add tests/api_smoke.php
git commit -m "test: add smoke test for JSON stats API"
```

---

## Task 3: Implement analytics/api.php

**Files:**
- Create: `analytics/api.php`

- [ ] **Step 1: Set a test API key in config temporarily**

Edit `analytics/includes/config.php` and change the empty string to a test value so you can run the smoke test:

```php
const API_KEY = 'test-api-key-change-me';
```

- [ ] **Step 2: Create `analytics/api.php`**

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';

// Auth: constant-time compare; empty key = disabled
$providedKey = isset($_GET['api_key']) && is_string($_GET['api_key']) ? $_GET['api_key'] : '';
if (API_KEY === '' || !hash_equals(API_KEY, $providedKey)) {
    json_response(['error' => 'Unauthorized'], 401);
    exit;
}

$pdo = analytics_pdo();
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

// Pageviews by month — all sites, newest first
$pvRows = $pdo->query(
    "SELECT strftime('%Y-%m', created_at) AS month,
            COUNT(*) AS pageviews,
            COUNT(DISTINCT visitor_id) AS unique_visitors
     FROM events
     WHERE event_type = 'pageview'
     GROUP BY month
     ORDER BY month DESC"
)->fetchAll();

// Link clicks by month — all sites, newest first then by click count desc
$lcRows = $pdo->query(
    "SELECT strftime('%Y-%m', created_at) AS month,
            event_name,
            COUNT(*) AS clicks
     FROM events
     WHERE event_type = 'link_click'
     GROUP BY month, event_name
     ORDER BY month DESC, clicks DESC"
)->fetchAll();

// Last 50 events — all types, all sites, newest first
$evRows = $pdo->query(
    "SELECT created_at, site, event_type, event_name, page_url
     FROM events
     ORDER BY id DESC
     LIMIT 50"
)->fetchAll();

json_response([
    'pageviews_by_month'   => $pvRows,
    'link_clicks_by_month' => $lcRows,
    'last_events'          => $evRows,
]);
```

- [ ] **Step 3: Run the smoke test against the local server**

The project uses Docker. Start the server:

```bash
bash start-server-docker.sh
```

Then run the smoke test (substitute your base URL if different):

```bash
php tests/api_smoke.php http://localhost:8080 test-api-key-change-me
```

Expected output:

```
PASS
```

If it fails, check:
- Is the server running and the database installed? (`http://localhost:8080/analytics/install.php`)
- Is `API_KEY` set to `'test-api-key-change-me'` in config?
- Does the URL path match your Docker setup?

- [ ] **Step 4: Reset API_KEY to empty (or your real key) and commit**

After the test passes, set the key back to `''` (disabled default) in `analytics/includes/config.php` — or to whatever production key you want to use. Document the key in your own secure notes; do not commit a real key.

```php
const API_KEY = '';
```

```bash
git add analytics/api.php analytics/includes/config.php
git commit -m "feat: add JSON stats API endpoint with API key auth"
```

---

## Self-Review

**Spec coverage check:**

| Spec requirement | Covered by |
|-----------------|-----------|
| `GET /analytics/api.php?api_key=` | Task 3 Step 2 |
| `API_KEY` constant in config, default `''` | Task 1 Step 1 |
| `hash_equals()` constant-time compare | Task 3 Step 2 |
| Empty key → disabled → 401 | Task 3 Step 2 + smoke test case 1 |
| Wrong key → 401 | Task 3 Step 2 + smoke test case 2 |
| `pageviews_by_month` dataset | Task 3 Step 2 |
| `link_clicks_by_month` dataset | Task 3 Step 2 |
| `last_events` dataset (LIMIT 50) | Task 3 Step 2 |
| No site filter | Task 3 Step 2 (no WHERE clause on site) |
| Reuse `json_response()` from helpers.php | Task 3 Step 2 |
| No changes to auth.php, db.php, helpers.php, dashboard | No other files touched |

No gaps found.
