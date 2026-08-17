<?php
// Copy this file to config.php and fill in your values.

define('DB_HOST', 'localhost');
define('DB_NAME', 'mtrcb_support');
define('DB_USER', 'root');
define('DB_PASS', 'YOUR_DB_PASSWORD');

// ── Upload directory ──────────────────────────────────────────────
// VPS Linux path for temporary file storage before forwarding to desktop
define('UPLOAD_DIR', '/var/mtrcb_uploads/');

// ── Desktop receiver (file tunnel) ───────────────────────────────
define('FORWARD_TO_DESKTOP',   true);
define('DESKTOP_RECEIVER_URL', 'http://YOUR_PUBLIC_IP:8081/mtrcb_receiver/receiver.php');
define('RECEIVER_SECRET',      'GENERATE_A_STRONG_RANDOM_SECRET');

// ── File limits ───────────────────────────────────────────────────
define('MAX_FILE_SIZE', 500 * 1024 * 1024); // 500 MB

define('ALLOWED_EXTENSIONS', [
    'mp4', 'avi', 'mkv', 'mov', 'wmv', 'flv',
    'webm', 'mpeg', 'mpg', '3gp', 'ts', 'm4v',
    'f4v', 'vob', 'rm', 'rmvb', 'divx', 'xvid',
]);

define('ALLOWED_MIMES', [
    'video/mp4', 'video/avi', 'video/x-msvideo', 'video/x-matroska',
    'video/quicktime', 'video/x-ms-wmv', 'video/x-flv', 'video/webm',
    'video/mpeg', 'video/3gpp', 'video/3gpp2', 'video/mp2t',
    'video/x-m4v', 'video/x-f4v', 'video/vob', 'application/octet-stream',
]);

// ── IP whitelist fallback ─────────────────────────────────────────
define('FALLBACK_WHITELIST', [
    '127.0.0.1',
    '::1',
    // Add your admin IPs here
]);

define('APP_NAME', 'MTRCB Support Portal');
define('SESSION_LIFETIME', 3600);
