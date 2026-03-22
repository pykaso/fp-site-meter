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
