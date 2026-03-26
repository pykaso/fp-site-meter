# First-party analytics (PHP + SQLite) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a minimal self-hosted first-party analytics stack (one PHP track endpoint, one vanilla `tracker.js`, SQLite file DB, password-protected PHP dashboard) deployable on shared PHP hosting with no Node, Docker, or external DB.

**Architecture:** Browser loads `tracker.js` (defer, `data-site`); it assigns/stores anonymous `visitor_id`, POSTs JSON to `track.php` for `pageview` and `link_click`. `track.php` validates input, hashes IP with a server secret, inserts rows via PDO prepared statements, applies basic anti-spam (rate limit by `ip_hash` + short window). Dashboard uses PHP sessions, `password_hash`/`password_verify`, CSRF on mutating forms, and parameterized SQL for monthly aggregates filtered by `site`.

**Tech stack:** PHP 8.x (procedural / light includes), PDO + SQLite, vanilla JS (fetch/sendBeacon), no Composer required (optional PHPUnit PHAR later).

---

## File structure (target)

| Path | Responsibility |
|------|----------------|
| `analytics/track.php` | Accept POST events; validate; insert `events`; rate-limit |
| `analytics/tracker.js` | Pageview on load; delegated clicks on `[data-track-click]` |
| `analytics/install.php` | Create SQLite schema + first admin; disable after success |
| `analytics/data/analytics.sqlite` | SQLite file (gitignored); created by install |
| `analytics/data/.htaccess` | Deny HTTP access to DB directory (Apache) |
| `analytics/includes/config.php` | Paths, `IP_HASH_SALT`, session name, limits |
| `analytics/includes/db.php` | `pdo()` singleton, `PRAGMA foreign_keys`, busy timeout |
| `analytics/includes/helpers.php` | `h()` escape, `csrf_token()`, `verify_csrf()`, `json_response()` |
| `analytics/includes/auth.php` | `require_login()`, `login()`, `logout()`, user load |
| `analytics/dashboard/index.php` | Monthly tables + site filter + optional last-50 events |
| `analytics/dashboard/login.php` | Email/password form + CSRF |
| `analytics/dashboard/logout.php` | Destroy session + redirect |
| `README.md` | Upload instructions, Apache/Nginx notes, snippet example |
| `.gitignore` | Ignore `analytics/data/*.sqlite`, `analytics/data/*.sqlite-*` |
| `tests/track_test.php` | CLI script: assert validation + DB insert (optional but TDD-friendly) |

---

### Task 1: Repository scaffold + guard `data/`

**Files:**
- Create: `.gitignore`
- Create: `analytics/data/.htaccess`
- Create: `README.md` (stub section “Installation — see install.php”)
- Create: `analytics/includes/config.php` (minimal constants: `BASE_PATH`, `DB_PATH`, `IP_HASH_SALT` placeholder)

- [ ] **Step 1: Add `.gitignore`**

```gitignore
analytics/data/*.sqlite
analytics/data/*.sqlite-*
analytics/data/*.db
```

- [ ] **Step 2: Add Apache deny rule for DB folder**

Create `analytics/data/.htaccess`:

```apache
Require all denied
```

- [ ] **Step 3: Stub `analytics/includes/config.php`**

```php
<?php
declare(strict_types=1);

define('ANALYTICS_ROOT', dirname(__DIR__));
define('ANALYTICS_DATA', ANALYTICS_ROOT . '/data');
define('DB_PATH', ANALYTICS_DATA . '/analytics.sqlite');

// CHANGE in production: long random string, keep secret
define('IP_HASH_SALT', 'change-me-in-install-or-config');

// String limits (anti-abuse)
define('MAX_SITE_LEN', 255);
define('MAX_EVENT_NAME_LEN', 128);
define('MAX_URL_LEN', 2048);
define('MAX_UA_LEN', 512);
```

- [ ] **Step 4: Commit**

```bash
git add .gitignore analytics/data/.htaccess analytics/includes/config.php README.md
git commit -m "chore: scaffold analytics data dir and config"
```

---

### Task 2: PDO bootstrap (`db.php`)

**Files:**
- Create: `analytics/includes/db.php`
- Modify: `analytics/includes/config.php` (if needed for DSN options)

- [ ] **Step 1: Write failing check (optional lightweight test)**

Create `tests/db_test.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../analytics/includes/config.php';
require __DIR__ . '/../analytics/includes/db.php';

// Expect failure before install: no sqlite file
try {
    $pdo = analytics_pdo();
    echo "UNEXPECTED: opened DB without file\n";
    exit(1);
} catch (Throwable $e) {
    echo "OK: expected error before install\n";
    exit(0);
}
```

- [ ] **Step 2: Run test — expect OK message**

```bash
php tests/db_test.php
```

Expected: `OK: expected error before install` (adjust message if your `analytics_pdo()` throws different type after Task 3).

- [ ] **Step 3: Implement `analytics/includes/db.php`**

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function analytics_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (!is_file(DB_PATH)) {
        throw new RuntimeException('Database not installed. Run install.php');
    }
    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec('PRAGMA busy_timeout = 5000;');
    return $pdo;
}
```

- [ ] **Step 4: Commit**

```bash
git add analytics/includes/db.php tests/db_test.php
git commit -m "feat(db): PDO singleton for SQLite"
```

---

### Task 3: `install.php` — schema + first admin

**Files:**
- Create: `analytics/install.php`
- Modify: `README.md` (install steps)

**Schema (minimum):**

```sql
CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);

CREATE TABLE events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  site TEXT NOT NULL,
  event_type TEXT NOT NULL,
  event_name TEXT NOT NULL DEFAULT '',
  page_url TEXT NOT NULL DEFAULT '',
  referrer TEXT NOT NULL DEFAULT '',
  visitor_id TEXT NOT NULL DEFAULT '',
  ip_hash TEXT NOT NULL DEFAULT '',
  user_agent TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);

CREATE INDEX idx_events_site_created ON events (site, created_at);
CREATE INDEX idx_events_type_created ON events (event_type, created_at);
```

- [ ] **Step 1: Implement `install.php`**

Behavior:
- If `install.lock` exists or DB already has tables → show message and exit (no overwrite).
- `mkdir` `analytics/data` if missing; create SQLite; run DDL.
- POST form: email + password + confirm + optional `IP_HASH_SALT` (or generate random).
- Write `install.lock` after success; update `config.php` salt if you choose file-patch (simpler: print “set IP_HASH_SALT in config.php” and stop).

Prefer **no auto-edit of config** for hosting safety: show generated salt for user to paste once.

- [ ] **Step 2: Manual verify**

```bash
php -S localhost:8080 -t .
# open http://localhost:8080/analytics/install.php in browser, complete form
```

Expected: `analytics/data/analytics.sqlite` exists; `users` has one row.

- [ ] **Step 3: Commit**

```bash
git add analytics/install.php README.md
git commit -m "feat: install wizard for SQLite schema and admin user"
```

---

### Task 4: Helpers — JSON, escape, CSRF

**Files:**
- Create: `analytics/includes/helpers.php`

- [ ] **Step 1: Implement helpers**

```php
<?php
declare(strict_types=1);

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_response(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $token);
}
```

- [ ] **Step 2: Commit**

```bash
git add analytics/includes/helpers.php
git commit -m "feat: helpers for escaping, JSON, CSRF"
```

---

### Task 5: `track.php` — POST-only ingest + validation + rate limit

**Files:**
- Create: `analytics/track.php`
- Modify: `analytics/includes/config.php` (rate limit constants)

**Contract (JSON body):**

```json
{
  "site": "example.com",
  "event_type": "pageview",
  "event_name": "",
  "page_url": "https://example.com/path",
  "referrer": "",
  "visitor_id": "uuid-like",
  "user_agent": ""
}
```

Rules:
- **Only POST**; others → 405 JSON `{"ok":false,"error":"method_not_allowed"}`.
- Parse JSON; reject malformed.
- Allow `event_type` in `['pageview','link_click']`.
- For `link_click`, require non-empty `event_name` (after trim); max lengths per constants.
- `ip_hash` = `hash('sha256', $ip . IP_HASH_SALT)`; if IP unknown, hash empty string + salt still (document in README).
- **Rate limit:** e.g. max 120 inserts / 60s per `ip_hash` (store in SQLite table `rate_buckets` or count recent rows in `events` — YAGNI: count last minute in `events` is enough at small scale).

- [ ] **Step 1: Write CLI/curl test script** `tests/track_smoke.php` that:
  - Starts PHP built-in server OR includes track with mocked `$_SERVER` (simpler: curl against running server).
  - POSTs valid JSON → expect `{"ok":true}`.
  - POSTs invalid `event_type` → 400.

Example curl (document in plan execution):

```bash
curl -s -X POST http://localhost:8080/analytics/track.php \
  -H 'Content-Type: application/json' \
  -d '{"site":"t.com","event_type":"pageview","event_name":"","page_url":"/","referrer":"","visitor_id":"v1","user_agent":"ua"}'
```

- [ ] **Step 2: Implement `track.php`** (procedural top-to-bottom: method check → JSON → validate → rate limit → insert).

Use prepared statement:

```php
$stmt = $pdo->prepare(
  'INSERT INTO events (site, event_type, event_name, page_url, referrer, visitor_id, ip_hash, user_agent)
   VALUES (:site, :etype, :ename, :purl, :ref, :vid, :iph, :ua)'
);
```

- [ ] **Step 3: Run smoke curl — expect `ok:true`**

- [ ] **Step 4: Commit**

```bash
git add analytics/track.php analytics/includes/config.php tests/track_smoke.php
git commit -m "feat(track): POST JSON ingest with validation and rate limit"
```

---

### Task 6: `tracker.js` — pageview + delegated link clicks

**Files:**
- Create: `analytics/tracker.js`

Requirements from spec:
- Read `data-site` from the script tag: `document.currentScript` (defer-safe).
- On `DOMContentLoaded` (or immediate if `document.readyState` complete), POST `pageview`.
- Delegate `click` on `document` for `a[data-track-click]`: send `link_click` with `event_name` from attribute; use `navigator.sendBeacon` if available with `Blob` + `type: application/json`, else `fetch(..., {keepalive:true})`.
- No external libs; keep small.

Pseudo-core:

```javascript
(function () {
  var sc = document.currentScript;
  var site = sc && sc.getAttribute('data-site');
  if (!site) return;
  var endpoint = new URL('track.php', sc.src).toString();

  function post(payload) {
    var body = JSON.stringify(payload);
    if (navigator.sendBeacon) {
      var blob = new Blob([body], { type: 'application/json' });
      navigator.sendBeacon(endpoint, blob);
    } else {
      fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true });
    }
  }

  function sendPageview() {
    post({
      site: site,
      event_type: 'pageview',
      event_name: '',
      page_url: location.href,
      referrer: document.referrer || '',
      visitor_id: getOrCreateVisitorId(),
      user_agent: navigator.userAgent || ''
    });
  }

  function getOrCreateVisitorId() {
    try {
      var k = 'analytics_vid';
      var v = localStorage.getItem(k);
      if (v) return v;
      v = (crypto.randomUUID && crypto.randomUUID()) || (Math.random() + '').slice(2);
      localStorage.setItem(k, v);
      return v;
    } catch (e) {
      return 'anon';
    }
  }

  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[data-track-click]');
    if (!a) return;
    var name = a.getAttribute('data-track-click');
    if (!name) return;
    post({
      site: site,
      event_type: 'link_click',
      event_name: name,
      page_url: location.href,
      referrer: document.referrer || '',
      visitor_id: getOrCreateVisitorId(),
      user_agent: navigator.userAgent || ''
    });
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', sendPageview);
  } else {
    sendPageview();
  }
})();
```

- [ ] **Step 1: Add `tracker.js` as above** (adjust endpoint resolution if script served from subdirectory).

- [ ] **Step 2: Manual browser test** — load a static HTML page with script + click tracked link; verify row in `events`.

- [ ] **Step 3: Commit**

```bash
git add analytics/tracker.js README.md
git commit -m "feat(tracker): vanilla first-party pageview and link_click"
```

---

### Task 7: Auth layer (`auth.php`) + session bootstrap

**Files:**
- Create: `analytics/includes/auth.php`
- Modify: `analytics/includes/config.php` (`SESSION_NAME` constant)

- [ ] **Step 1: Implement session start helper** `analytics_session_start()` secure flags: `httponly`, `samesite=Lax` (PHP 7.3+ `session_set_cookie_params` array).

- [ ] **Step 2: Implement `require_login()`** — redirect to `login.php` if no user id in session.

- [ ] **Step 3: Implement `attempt_login(email, password)`** using PDO + `password_verify`.

- [ ] **Step 4: Commit**

```bash
git add analytics/includes/auth.php analytics/includes/config.php
git commit -m "feat(auth): session login helpers for dashboard"
```

---

### Task 8: Dashboard login / logout + CSRF

**Files:**
- Create: `analytics/dashboard/login.php`
- Create: `analytics/dashboard/logout.php`

- [ ] **Step 1: `login.php`** — `analytics_session_start()`; if POST, verify CSRF, validate email/password, set `$_SESSION['user_id']`, regenerate session id, redirect `index.php`.

- [ ] **Step 2: `logout.php`** — CSRF optional on GET logout (prefer POST form “Logout” on dashboard); simplest safe pattern: POST with CSRF only.

- [ ] **Step 3: Manual test** — wrong password rejected; correct password lands on dashboard (stub index OK until Task 9).

- [ ] **Step 4: Commit**

```bash
git add analytics/dashboard/login.php analytics/dashboard/logout.php
git commit -m "feat(dashboard): login and logout with CSRF"
```

---

### Task 9: Dashboard `index.php` — monthly stats + site filter + last events

**Files:**
- Create: `analytics/dashboard/index.php`

Queries (SQLite, UTC `created_at` stored as ISO-8601 text — use `strftime` for month bucket):

**Pageviews by month + unique visitors:**

```sql
SELECT strftime('%Y-%m', created_at) AS month,
       COUNT(*) AS pageviews,
       COUNT(DISTINCT visitor_id) AS unique_visitors
FROM events
WHERE event_type = 'pageview'
  AND (:site = '' OR site = :site)
GROUP BY month
ORDER BY month DESC;
```

**Link clicks by month:**

```sql
SELECT strftime('%Y-%m', created_at) AS month,
       event_name,
       COUNT(*) AS clicks
FROM events
WHERE event_type = 'link_click'
  AND (:site = '' OR site = :site)
GROUP BY month, event_name
ORDER BY month DESC, clicks DESC;
```

**Site list for filter:**

```sql
SELECT DISTINCT site FROM events ORDER BY site;
```

**Last 50 events:**

```sql
SELECT created_at, site, event_type, event_name, page_url
FROM events
WHERE (:site = '' OR site = :site)
ORDER BY id DESC
LIMIT 50;
```

UI:
- Top: form GET `?site=` dropdown (CSRF not required for GET).
- Tables: escape all cells with `h()`.
- Minimal HTML/CSS (no framework).

- [ ] **Step 1: Implement `index.php`** with `require_login()` at top.

- [ ] **Step 2: Manual verification** — seed a few rows or use tracker; tables match expectations.

- [ ] **Step 3: Commit**

```bash
git add analytics/dashboard/index.php
git commit -m "feat(dashboard): monthly aggregates and site filter"
```

---

### Task 10: README + production hardening notes

**Files:**
- Modify: `README.md`

Document:
- Upload `analytics/` to web root; chmod `data/` writable.
- Run `install.php` once; delete or rename `install.php` after.
- Set strong `IP_HASH_SALT` in `config.php`.
- Nginx: deny `/analytics/data/` (equivalent to `.htaccess`).
- Snippet example from spec.
- Privacy note: hashed IP, anonymous visitor id, first-party only.

- [ ] **Step 1: Final README pass**

- [ ] **Step 2: Commit**

```bash
git add README.md
git commit -m "docs: installation and security notes"
```

---

## Plan review (manual)

Before execution, optionally review this plan for:
- Hosting without Apache (Nginx `location` deny rules).
- Whether `install.lock` should live outside webroot (e.g. `data/install.lock` is fine if `data/` denied).
- Legal/privacy: README should state operator is responsible for consent/cookie rules in their jurisdiction.

---

## Execution handoff

**Plan saved to:** `docs/superpowers/plans/2026-03-22-first-party-analytics-php-hosting.md`

**Two execution options:**

1. **Subagent-driven (recommended)** — fresh subagent per task, review between tasks.  
2. **Inline execution** — same session, batch tasks with checkpoints (`superpowers:executing-plans`).

**Which approach do you want?**
