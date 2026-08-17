<?php
require_once __DIR__ . '/includes/ip_guard.php';
require_once __DIR__ . '/includes/auth.php';

startSecureSession();
if (isLoggedIn()) {
    header('Location: ' . roleDashboard($_SESSION['role'] ?? 'uploader'));
} else {
    header('Location: /mtrcb_support/login.php');
}
exit;
