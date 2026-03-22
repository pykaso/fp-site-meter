<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

require_login();

$pdo = analytics_pdo();
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

$user = current_user();
if ($user === null) {
    header('Location: login.php');
    exit;
}

$siteRaw = isset($_GET['site']) && is_string($_GET['site']) ? $_GET['site'] : '';
$siteRaw = trim($siteRaw);
if (strlen($siteRaw) > MAX_SITE_LEN) {
    $siteRaw = substr($siteRaw, 0, MAX_SITE_LEN);
}
$siteFilter = '';
if ($siteRaw !== '' && $siteRaw !== '__all__') {
    $siteFilter = $siteRaw;
}

$siteBind = ['site' => $siteFilter];

try {
    $sitesStmt = $pdo->query('SELECT DISTINCT site FROM events ORDER BY site COLLATE NOCASE');
} catch (PDOException $e) {
    $sitesStmt = $pdo->query('SELECT DISTINCT site FROM events ORDER BY site');
}
$distinctSites = $sitesStmt->fetchAll(PDO::FETCH_COLUMN, 0);
$distinctSites = array_map(static fn ($s) => (string) $s, $distinctSites);

$pvStmt = $pdo->prepare(
    "SELECT strftime('%Y-%m', created_at) AS month,
       COUNT(*) AS pageviews,
       COUNT(DISTINCT visitor_id) AS unique_visitors
FROM events
WHERE event_type = 'pageview'
  AND (:site = '' OR site = :site)
GROUP BY month
ORDER BY month DESC"
);
$pvStmt->execute($siteBind);
$pageviewsByMonth = $pvStmt->fetchAll();

$lcStmt = $pdo->prepare(
    "SELECT strftime('%Y-%m', created_at) AS month,
       event_name,
       COUNT(*) AS clicks
FROM events
WHERE event_type = 'link_click'
  AND (:site = '' OR site = :site)
GROUP BY month, event_name
ORDER BY month DESC, clicks DESC"
);
$lcStmt->execute($siteBind);
$linkClicksByMonth = $lcStmt->fetchAll();

$evStmt = $pdo->prepare(
    "SELECT created_at, site, event_type, event_name, page_url
FROM events
WHERE (:site = '' OR site = :site)
ORDER BY id DESC
LIMIT 50"
);
$evStmt->execute($siteBind);
$lastEvents = $evStmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Analytics</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; margin: 1.25rem; line-height: 1.45; }
        h1 { margin-top: 0; }
        table { border-collapse: collapse; width: 100%; max-width: 56rem; margin-bottom: 1.5rem; }
        th, td { border: 1px solid #ccc; padding: 0.35rem 0.5rem; text-align: left; vertical-align: top; }
        th { background: #f4f4f4; }
        form { margin-bottom: 1rem; }
        .meta { color: #444; margin-bottom: 1rem; }
        .row { display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end; margin-bottom: 1rem; }
        .logout { margin-top: 0.5rem; }
    </style>
</head>
<body>
    <h1>Analytics</h1>
    <p class="meta">Signed in as <?= h((string) ($user['email'] ?? '')) ?></p>

    <div class="row">
        <form method="get" action="">
            <label for="site">Site</label><br>
            <select id="site" name="site">
                <option value="__all__"<?= $siteFilter === '' ? ' selected' : '' ?>>All sites</option>
                <?php foreach ($distinctSites as $s): ?>
                    <option value="<?= h($s) ?>"<?= $siteFilter === $s ? ' selected' : '' ?>><?= h($s) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Apply</button>
        </form>
        <form class="logout" method="post" action="logout.php">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <button type="submit">Log out</button>
        </form>
    </div>

    <h2>Pageviews by month</h2>
    <table>
        <thead>
            <tr>
                <th>Month</th>
                <th>Pageviews</th>
                <th>Unique visitors</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($pageviewsByMonth === []): ?>
                <tr><td colspan="3"><?= h('No data') ?></td></tr>
            <?php else: ?>
                <?php foreach ($pageviewsByMonth as $row): ?>
                    <tr>
                        <td><?= h((string) ($row['month'] ?? '')) ?></td>
                        <td><?= h((string) ($row['pageviews'] ?? '')) ?></td>
                        <td><?= h((string) ($row['unique_visitors'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <h2>Link clicks by month</h2>
    <table>
        <thead>
            <tr>
                <th>Month</th>
                <th>Event name</th>
                <th>Clicks</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($linkClicksByMonth === []): ?>
                <tr><td colspan="3"><?= h('No data') ?></td></tr>
            <?php else: ?>
                <?php foreach ($linkClicksByMonth as $row): ?>
                    <tr>
                        <td><?= h((string) ($row['month'] ?? '')) ?></td>
                        <td><?= h((string) ($row['event_name'] ?? '')) ?></td>
                        <td><?= h((string) ($row['clicks'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <h2>Last 50 events</h2>
    <table>
        <thead>
            <tr>
                <th>Created</th>
                <th>Site</th>
                <th>Event type</th>
                <th>Event name</th>
                <th>Page URL</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($lastEvents === []): ?>
                <tr><td colspan="5"><?= h('No data') ?></td></tr>
            <?php else: ?>
                <?php foreach ($lastEvents as $row): ?>
                    <tr>
                        <td><?= h((string) ($row['created_at'] ?? '')) ?></td>
                        <td><?= h((string) ($row['site'] ?? '')) ?></td>
                        <td><?= h((string) ($row['event_type'] ?? '')) ?></td>
                        <td><?= h((string) ($row['event_name'] ?? '')) ?></td>
                        <td><?= h((string) ($row['page_url'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
