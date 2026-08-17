<?php
require_once __DIR__ . '/includes/ip_guard.php';
require_once __DIR__ . '/includes/auth.php';

startSecureSession();
logout();
header('Location: /mtrcb_support/login.php');
exit;
