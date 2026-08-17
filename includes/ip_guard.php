<?php
/**
 * IP Guard — include at the very top of every page.
 * Blocks all requests from non-whitelisted IPs (program-level restriction).
 */
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';

function getClientIP(): string {
    // Do NOT trust X-Forwarded-For — use REMOTE_ADDR only to prevent spoofing.
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function isIPWhitelisted(string $ip): bool {
    $db = getDB();
    if ($db !== null) {
        try {
            $stmt = $db->prepare('SELECT id FROM ip_whitelist WHERE ip_address = ? AND active = 1 LIMIT 1');
            $stmt->execute([$ip]);
            if ($stmt->fetch()) {
                return true;
            }
        } catch (PDOException $e) {
            // DB error — fall through to config fallback
        }
    }
    return in_array($ip, FALLBACK_WHITELIST, true);
}

$__clientIP = getClientIP();
if (!isIPWhitelisted($__clientIP)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>403 Forbidden — MTRCB Support</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', sans-serif; background: #0a1628; color: #c9d1e0; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { background: #111d30; border: 1px solid #1e3a5f; border-radius: 12px; padding: 48px 56px; text-align: center; max-width: 480px; }
        .shield { font-size: 64px; margin-bottom: 24px; }
        h1 { font-size: 28px; color: #e8ecf4; margin-bottom: 12px; }
        p { color: #8a9ab5; line-height: 1.6; margin-bottom: 8px; }
        .ip { font-family: monospace; background: #0d1e35; padding: 6px 14px; border-radius: 6px; font-size: 13px; color: #5a9fd4; display: inline-block; margin-top: 16px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="shield">🛡️</div>
        <h1>403 — Access Denied</h1>
        <p>Your IP address is not authorized to access this system.</p>
        <p>Contact the system administrator to request access.</p>
        <div class="ip"><?= htmlspecialchars($__clientIP) ?></div>
    </div>
</body>
</html>
    <?php
    exit;
}
