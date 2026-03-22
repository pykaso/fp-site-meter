<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * @return PDO Shared SQLite connection (singleton).
 */
function analytics_pdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_file(DB_PATH)) {
        throw new RuntimeException('Database not installed. Run install.php');
    }

    $pdo = new PDO(
        'sqlite:' . DB_PATH,
        null,
        null,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec('PRAGMA busy_timeout = 5000;');

    return $pdo;
}
