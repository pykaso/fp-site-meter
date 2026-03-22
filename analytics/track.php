<?php

declare(strict_types=1);

/**
 * First-party event ingest (same-origin POST JSON only; no CORS).
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
        exit;
    }

    $raw = file_get_contents('php://input');
    if ($raw === false) {
        $raw = '';
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_response(['ok' => false, 'error' => 'invalid_json'], 400);
        exit;
    }

    $str = static function (mixed $v): string {
        if (is_string($v)) {
            return $v;
        }
        if (is_scalar($v) || $v === null) {
            return trim((string) $v);
        }

        return '';
    };

    $site = trim($str($data['site'] ?? ''));
    $eventType = trim($str($data['event_type'] ?? ''));
    $eventName = $str($data['event_name'] ?? '');
    $pageUrl = trim($str($data['page_url'] ?? ''));
    $referrer = trim($str($data['referrer'] ?? ''));
    $visitorId = trim($str($data['visitor_id'] ?? ''));
    $userAgent = trim($str($data['user_agent'] ?? ''));

    if ($site === '' || mb_strlen($site, 'UTF-8') > MAX_SITE_LEN) {
        json_response(['ok' => false, 'error' => 'invalid_site'], 400);
        exit;
    }

    if ($eventType !== 'pageview' && $eventType !== 'link_click') {
        json_response(['ok' => false, 'error' => 'invalid_event_type'], 400);
        exit;
    }

    if ($eventType === 'pageview' && !array_key_exists('event_name', $data)) {
        $eventName = '';
    }

    if ($eventType === 'pageview') {
        $eventName = trim($eventName);
    }

    if ($eventType === 'link_click') {
        $eventName = trim($eventName);
        if ($eventName === '' || mb_strlen($eventName, 'UTF-8') > MAX_EVENT_NAME_LEN) {
            json_response(['ok' => false, 'error' => 'invalid_event_name'], 400);
            exit;
        }
    } elseif (mb_strlen($eventName, 'UTF-8') > MAX_EVENT_NAME_LEN) {
        json_response(['ok' => false, 'error' => 'invalid_event_name'], 400);
        exit;
    }

    if (mb_strlen($pageUrl, 'UTF-8') > MAX_URL_LEN || mb_strlen($referrer, 'UTF-8') > MAX_URL_LEN) {
        json_response(['ok' => false, 'error' => 'invalid_url'], 400);
        exit;
    }

    if (mb_strlen($visitorId, 'UTF-8') > MAX_VISITOR_ID_LEN) {
        json_response(['ok' => false, 'error' => 'invalid_visitor_id'], 400);
        exit;
    }

    if (mb_strlen($userAgent, 'UTF-8') > MAX_UA_LEN) {
        json_response(['ok' => false, 'error' => 'invalid_user_agent'], 400);
        exit;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ipHash = hash('sha256', $ip . IP_HASH_SALT);

    $pdo = analytics_pdo();

    $windowSecs = TRACK_RATE_LIMIT_WINDOW_SECONDS;
    if ($windowSecs < 1) {
        $windowSecs = 1;
    }

    $rl = $pdo->prepare(
        "SELECT COUNT(*) FROM events WHERE ip_hash = :iph AND created_at > datetime('now', '-' || :secs || ' seconds')"
    );
    $rl->bindValue(':iph', $ipHash, PDO::PARAM_STR);
    $rl->bindValue(':secs', (string) $windowSecs, PDO::PARAM_STR);
    $rl->execute();
    $count = (int) $rl->fetchColumn();

    if ($count >= TRACK_RATE_LIMIT_MAX_EVENTS) {
        json_response(['ok' => false, 'error' => 'too_many_requests'], 429);
        exit;
    }

    $ins = $pdo->prepare(
        'INSERT INTO events (site, event_type, event_name, page_url, referrer, visitor_id, ip_hash, user_agent)
         VALUES (:site, :etype, :ename, :purl, :ref, :vid, :iph, :ua)'
    );
    $ins->execute([
        'site' => $site,
        'etype' => $eventType,
        'ename' => $eventName,
        'purl' => $pageUrl,
        'ref' => $referrer,
        'vid' => $visitorId,
        'iph' => $ipHash,
        'ua' => $userAgent,
    ]);

    json_response(['ok' => true], 200);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'server_error'], 500);
    exit;
}
