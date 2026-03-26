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
