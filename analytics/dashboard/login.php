<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

analytics_session_start();

if (current_user() !== null) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : '';
    if (!verify_csrf($csrf)) {
        $error = 'Invalid request.';
    } else {
        $email = isset($_POST['email']) && is_string($_POST['email']) ? $_POST['email'] : '';
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        if (attempt_login($email, $password)) {
            session_regenerate_id(true);
            header('Location: index.php');
            exit;
        }
        $error = 'Invalid email or password.';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Dashboard login</title>
</head>
<body>
<?php if ($error !== '') { ?>
    <p><?= h($error) ?></p>
<?php } ?>
    <form method="post" action="login.php">
        <p>
            <label for="email">Email</label>
            <input type="email" id="email" name="email" required autocomplete="username">
        </p>
        <p>
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
        </p>
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <p><button type="submit">Sign in</button></p>
    </form>
</body>
</html>
