# First-party analytics (PHP + SQLite)

Lightweight self-hosted analytics using PHP and SQLite.

## Installation

1. Upload the `analytics/` directory to your server.
2. Make `analytics/data/` writable by the web server (e.g. `chmod` so PHP can create files there).
3. Open `/analytics/install.php` in a browser, complete the form (admin email, password, optional IP hash salt).
4. **Delete or rename `analytics/install.php`** after a successful install so it cannot be run again from the web.
5. Set `IP_HASH_SALT` in `analytics/includes/config.php` to the value shown on the success page (the installer does not edit that file).

Keep `analytics/data/` non-public on your web server (e.g. deny HTTP access to that directory).

## Tracking snippet

Add the tracker on every page you want to measure (same origin as `analytics/`):

```html
<script defer src="/analytics/tracker.js" data-site="yourdomain"></script>
```

Use `data-site` to match the site identifier stored with your events. To record named link clicks without blocking navigation:

```html
<a href="/pricing" data-track-click="cta_pricing">Pricing</a>
```

## Requirements

- **PHP 8+** with extensions used by install and tracking: **PDO SQLite**, **JSON**, **mbstring**, and **sessions** (dashboard login).
- **Web server:** block public HTTP access to the database directory. With **Apache**, the repo ships `analytics/data/.htaccess` to deny access. With **Nginx**, add a rule such as:

```nginx
location ^~ /analytics/data/ {
    deny all;
    return 403;
}
```

## Security / operations

- After install: **remove or rename** `install.php`, and set a strong **`IP_HASH_SALT`** in `analytics/includes/config.php` (the installer does not write secrets into that file for you).
- **Dashboard:** uses a PHP **session cookie** (`DASH_SESSION_NAME` in config). In production, serve the site over **HTTPS** so the session cookie can use the **`secure`** flag (the app sets it automatically when the request is HTTPS).
- **Tracking:** `track.php` accepts **POST JSON** from the **same origin** as the tracker (no CORS). Visitor IPs are stored as **SHA-256 with salt**, not raw IPs.
- **Rate limiting** on the track endpoint is configured in `analytics/includes/config.php`: **`TRACK_RATE_LIMIT_WINDOW_SECONDS`** and **`TRACK_RATE_LIMIT_MAX_EVENTS`**.

## Privacy / legal (brief)

The **operator** is responsible for **consent**, **cookie**, and **privacy** rules in their jurisdiction. This tool assigns visitors an **anonymous random ID** in **localStorage** (first-party only); it does not embed third-party trackers.

## Quick links

- **Dashboard (login):** `/analytics/dashboard/`
- **Tracker script:** `/analytics/tracker.js`
- **Do not** expose `analytics/data/` over HTTP (SQLite and sensitive paths must stay private).
