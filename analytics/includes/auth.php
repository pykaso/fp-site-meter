<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function analytics_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name(DASH_SESSION_NAME);

    $https = $_SERVER['HTTPS'] ?? '';
    $secure = ($https !== '' && strtolower((string) $https) !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $secure,
    ]);

    session_start();
}

function require_login(): void
{
    analytics_session_start();

    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($userId === false) {
        header('Location: login.php');
        exit;
    }
}

function attempt_login(string $email, string $password): bool
{
    $email = trim($email);
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }

    $pdo = analytics_pdo();
    $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = :e LIMIT 1');
    $stmt->execute(['e' => $email]);
    $row = $stmt->fetch();

    if ($row === false || !password_verify($password, (string) $row['password_hash'])) {
        return false;
    }

    analytics_session_start();
    $_SESSION['user_id'] = (int) $row['id'];

    return true;
}

function current_user(): ?array
{
    analytics_session_start();

    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($userId === false) {
        return null;
    }

    $pdo = analytics_pdo();
    $stmt = $pdo->prepare('SELECT id, email, created_at FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function logout_user(): void
{
    analytics_session_start();
    session_unset();
    session_destroy();
}
