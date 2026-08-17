<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';

function startSecureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_strict_mode', '1');
        session_start();
    }
}

function generateCsrf(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function login(string $username, string $password): array {
    $db = getDB();
    if ($db === null) return ['ok' => false, 'error' => 'Database unavailable.'];

    $stmt = $db->prepare('SELECT id, username, password, full_name, role, active FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([trim($username)]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        return ['ok' => false, 'error' => 'Invalid username or password.'];
    }
    if (!$user['active']) {
        return ['ok' => false, 'error' => 'Account is disabled. Contact administrator.'];
    }

    session_regenerate_id(true);
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['username']  = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role']      = $user['role'];
    $_SESSION['login_at']  = time();

    $db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
    return ['ok' => true, 'role' => $user['role']];
}

function logout(): void {
    session_unset();
    session_destroy();
    session_start();
    session_regenerate_id(true);
}

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']) &&
           isset($_SESSION['login_at']) &&
           (time() - $_SESSION['login_at']) < SESSION_LIFETIME;
}

function requireLogin(string $redirect = '/mtrcb_support/login.php'): void {
    startSecureSession();
    if (!isLoggedIn()) { header('Location: ' . $redirect); exit; }
}

function requireAdmin(): void {
    requireLogin();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        header('Location: /mtrcb_support/index.php'); exit;
    }
}

function requireUploader(): void {
    requireLogin();
    $role = $_SESSION['role'] ?? '';
    if (!in_array($role, ['uploader', 'admin'], true)) {
        header('Location: /mtrcb_support/index.php'); exit;
    }
}

function requireVerifier(): void {
    requireLogin();
    $role = $_SESSION['role'] ?? '';
    if (!in_array($role, ['verifier', 'admin'], true)) {
        header('Location: /mtrcb_support/index.php'); exit;
    }
}

function currentUser(): array {
    return [
        'id'        => $_SESSION['user_id']  ?? 0,
        'username'  => $_SESSION['username'] ?? '',
        'full_name' => $_SESSION['full_name'] ?? '',
        'role'      => $_SESSION['role']     ?? 'uploader',
    ];
}

function roleDashboard(string $role): string {
    return match($role) {
        'admin'    => '/mtrcb_support/admin/index.php',
        'verifier' => '/mtrcb_support/verifier/index.php',
        'uploader' => '/mtrcb_support/uploader/index.php',
        default    => '/mtrcb_support/login.php',
    };
}
