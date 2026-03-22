<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

function install_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Escape for use inside a single-quoted PHP string literal (copy-paste into config). */
function install_php_single_quoted(string $s): string
{
    return str_replace(['\\', "'"], ['\\\\', "\\'"], $s);
}

function install_already_installed_html(): string
{
    return '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Already installed</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 40rem; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; }
    code { background: #f4f4f4; padding: 0.15em 0.35em; border-radius: 4px; }
  </style>
</head>
<body>
  <h1>Already installed</h1>
  <p>This analytics instance is already installed. The installer does not overwrite or recreate the database schema.</p>
  <p>To run a fresh install, remove <code>analytics/data/install.lock</code> and, if you want a clean database, delete <code>analytics/data/analytics.sqlite</code> (and related SQLite sidecar files if any), then open this page again.</p>
</body>
</html>';
}

function install_db_has_users_table(): bool
{
    if (!is_file(DB_PATH)) {
        return false;
    }

    $pdo = new PDO(
        'sqlite:' . DB_PATH,
        null,
        null,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]
    );

    $stmt = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'users' LIMIT 1");

    return (bool) $stmt->fetchColumn();
}

$lockPath = ANALYTICS_DATA . '/install.lock';

if (is_file($lockPath) || (is_file(DB_PATH) && install_db_has_users_table())) {
    header('Content-Type: text/html; charset=utf-8');
    echo install_already_installed_html();
    exit;
}

if (!is_dir(ANALYTICS_DATA)) {
    if (!mkdir(ANALYTICS_DATA, 0755, true) && !is_dir(ANALYTICS_DATA)) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Install error</title></head><body><p>Could not create the data directory. Check permissions on <code>analytics/data</code>.</p></body></html>';
        exit;
    }
}

$errors = [];
$email = '';
$ipHashSaltInput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = isset($_POST['email']) && is_string($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
    $passwordConfirm = isset($_POST['password_confirm']) && is_string($_POST['password_confirm']) ? $_POST['password_confirm'] : '';
    $ipHashSaltInput = isset($_POST['ip_hash_salt']) && is_string($_POST['ip_hash_salt']) ? trim($_POST['ip_hash_salt']) : '';

    $validEmail = filter_var($email, FILTER_VALIDATE_EMAIL);
    if ($validEmail === false) {
        $errors[] = 'Please enter a valid email address.';
    } else {
        $email = $validEmail;
    }

    if ($password !== $passwordConfirm) {
        $errors[] = 'Password and confirmation do not match.';
    }

    if (mb_strlen($password, 'UTF-8') < 10) {
        $errors[] = 'Password must be at least 10 characters.';
    }

    $saltForConfig = $ipHashSaltInput !== '' ? $ipHashSaltInput : bin2hex(random_bytes(32));

    if ($errors === []) {
        try {
            $pdo = new PDO(
                'sqlite:' . DB_PATH,
                null,
                null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]
            );

            $stmt = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'users' LIMIT 1");
            $hasUsersTable = (bool) $stmt->fetchColumn();

            if (!$hasUsersTable) {
                $pdo->exec("CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
)");

                $pdo->exec("CREATE TABLE events (
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
)");

                $pdo->exec('CREATE INDEX idx_events_site_created ON events (site, created_at)');
                $pdo->exec('CREATE INDEX idx_events_type_created ON events (event_type, created_at)');
            }

            $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if ($count > 0) {
                $errors[] = 'A user already exists. Remove the database or lock file only if you intend to reinstall.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $ins = $pdo->prepare('INSERT INTO users (email, password_hash) VALUES (:email, :hash)');
                $ins->execute(['email' => $email, 'hash' => $hash]);

                file_put_contents($lockPath, gmdate('Y-m-d\TH:i:s\Z') . "\n");

                header('Content-Type: text/html; charset=utf-8');
                $configLine = "const IP_HASH_SALT = '" . install_php_single_quoted($saltForConfig) . "';";
                echo '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Installation complete</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 44rem; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; }
    pre { background: #f4f4f4; padding: 1rem; overflow-x: auto; border-radius: 6px; }
    code { background: #f4f4f4; padding: 0.15em 0.35em; border-radius: 4px; }
  </style>
</head>
<body>
  <h1>Installation complete</h1>
  <p>Your admin account has been created. <strong>Delete or rename <code>analytics/install.php</code></strong> on the server so it cannot be run again from the web.</p>
  <h2>Set IP hash salt in config</h2>
  <p>Copy the line below into <code>analytics/includes/config.php</code>. That file uses a PHP <code>const</code> (<code>const IP_HASH_SALT = \'…\';</code> at file scope), not <code>define(\'IP_HASH_SALT\', …)</code>. Replace the existing placeholder line:</p>
  <pre>' . install_h($configLine) . '</pre>
  <p>The installer does not modify <code>config.php</code> for you; edit the file manually, then save and upload if needed.</p>
</body>
</html>';
                exit;
            }
        } catch (Throwable $e) {
            $errors[] = 'Database error: could not complete installation. Check that the data directory is writable.';
        }
    }
}

header('Content-Type: text/html; charset=utf-8');
$errorHtml = '';
foreach ($errors as $err) {
    $errorHtml .= '<p class="err">' . install_h($err) . '</p>';
}

echo '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Install analytics</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 28rem; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; }
    label { display: block; margin-top: 0.75rem; font-weight: 600; }
    input[type="email"], input[type="password"], input[type="text"] { width: 100%; box-sizing: border-box; padding: 0.5rem; margin-top: 0.25rem; }
    button { margin-top: 1.25rem; padding: 0.5rem 1rem; }
    .err { color: #b00020; }
    .hint { font-weight: normal; font-size: 0.9rem; color: #444; }
  </style>
</head>
<body>
  <h1>Install analytics</h1>
' . $errorHtml . '
  <form method="post" action="" autocomplete="off">
    <label for="email">Admin email</label>
    <input type="email" id="email" name="email" required value="' . install_h($email) . '">

    <label for="password">Password</label>
    <input type="password" id="password" name="password" required minlength="10" autocomplete="new-password">

    <label for="password_confirm">Confirm password</label>
    <input type="password" id="password_confirm" name="password_confirm" required minlength="10" autocomplete="new-password">

    <label for="ip_hash_salt">IP hash salt <span class="hint">(optional; leave empty to generate a random salt)</span></label>
    <input type="text" id="ip_hash_salt" name="ip_hash_salt" value="' . install_h($ipHashSaltInput) . '" autocomplete="off">

    <button type="submit">Install</button>
  </form>
</body>
</html>';
