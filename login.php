<?php
require_once __DIR__ . '/includes/ip_guard.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

startSecureSession();

if (isLoggedIn()) {
    header('Location: ' . roleDashboard($_SESSION['role'] ?? 'uploader'));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $result = login($_POST['username'] ?? '', $_POST['password'] ?? '');
        if ($result['ok']) {
            header('Location: ' . roleDashboard($result['role']));
            exit;
        }
        $error = $result['error'];
    }
}

$csrf = generateCsrf();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login — MTRCB Support Portal</title>
    <link rel="stylesheet" href="/mtrcb_support/assets/style.css">
</head>
<body>
<div class="login-page">
    <div class="login-card">
        <div class="login-logo">
            <h2>🎬 MTRCB Support Portal</h2>
            <p>Movie and Television Review and Classification Board</p>
        </div>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username"
                       value="<?= e($_POST['username'] ?? '') ?>"
                       autocomplete="username" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                       autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block" style="margin-top:8px">Sign In</button>
        </form>
    </div>
</div>
</body>
</html>
