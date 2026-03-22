# First-party analytics (PHP + SQLite)

Lightweight self-hosted analytics using PHP and SQLite.

## Installation

1. Upload the `analytics/` directory to your server.
2. Make `analytics/data/` writable by the web server (e.g. `chmod` so PHP can create files there).
3. Open `/analytics/install.php` in a browser, complete the form (admin email, password, optional IP hash salt).
4. **Delete or rename `analytics/install.php`** after a successful install so it cannot be run again from the web.
5. Set `IP_HASH_SALT` in `analytics/includes/config.php` to the value shown on the success page (the installer does not edit that file).

Keep `analytics/data/` non-public on your web server (e.g. deny HTTP access to that directory).
