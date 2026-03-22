<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

analytics_session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : '';
    if (verify_csrf($csrf)) {
        logout_user();
    }
    header('Location: login.php');
    exit;
}

if (current_user() === null) {
    header('Location: login.php');
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Logout</title>
</head>
<body>
    <form method="post" action="logout.php">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <p><button type="submit">Logout</button></p>
    </form>
</body>
</html>
