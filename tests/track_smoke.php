<?php

declare(strict_types=1);

$base = $argv[1] ?? '';
if ($base === '') {
    fwrite(STDERR, "usage: php tests/track_smoke.php <baseUrl>\n");
    exit(1);
}

$base = rtrim($base, '/');
$url = $base . '/analytics/track.php';

/**
 * @return array{0: int, 1: string}
 */
function post_json(string $url, array $body): array
{
    $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        $payload = '{}';
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payload,
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

$okPageview = [
    'site' => 'smoke-test',
    'event_type' => 'pageview',
    'event_name' => '',
    'page_url' => 'https://example.com/path',
    'referrer' => '',
    'visitor_id' => 'smoke-visitor',
    'user_agent' => 'track_smoke/1.0',
];

[$code1, $body1] = post_json($url, $okPageview);
$pass1 = $code1 === 200 && str_contains($body1, '"ok":true');

$badType = $okPageview;
$badType['event_type'] = 'not_a_valid_type';

[$code2, $body2] = post_json($url, $badType);
$pass2 = $code2 === 400;

if ($pass1 && $pass2) {
    echo "PASS\n";
    exit(0);
}

echo "FAIL\n";
if (!$pass1) {
    echo "  pageview: expected HTTP 200 and body containing \"ok\":true; got {$code1} " . substr($body1, 0, 200) . "\n";
}
if (!$pass2) {
    echo "  invalid event_type: expected HTTP 400; got {$code2} " . substr($body2, 0, 200) . "\n";
}
exit(1);
