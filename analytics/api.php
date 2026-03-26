<?php

/**
 * Read-only JSON stats endpoint, authenticated by API key.
 */

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
