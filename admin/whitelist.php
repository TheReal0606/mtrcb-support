<?php
require_once dirname(__DIR__) . '/includes/ip_guard.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/config/db.php';

startSecureSession();
requireAdmin();

$user = currentUser();
$db   = getDB();
$csrf = generateCsrf();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $ip   = trim($_POST['ip_address'] ?? '');
            $desc = trim($_POST['description'] ?? '');

            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                $error = 'Invalid IP address format.';
            } else {
                try {
                    $stmt = $db->prepare('INSERT INTO ip_whitelist (ip_address, description, added_by) VALUES (?, ?, ?)');
                    $stmt->execute([$ip, $desc, $user['id']]);
                    $success = "IP $ip added to whitelist.";
                } catch (PDOException $e) {
                    $error = 'IP already exists in whitelist.';
                }
            }

        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare('UPDATE ip_whitelist SET active = 1 - active WHERE id = ?')->execute([$id]);
            $success = 'IP status toggled.';

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            // Prevent removing own IP
            $clientIP = getClientIP();
            $row = $db->prepare('SELECT ip_address FROM ip_whitelist WHERE id = ?');
            $row->execute([$id]);
            $r = $row->fetch();
            if ($r && $r['ip_address'] === $clientIP) {
                $error = 'Cannot remove your own IP — you would lock yourself out.';
            } else {
                $db->prepare('DELETE FROM ip_whitelist WHERE id = ?')->execute([$id]);
                $success = 'IP removed.';
            }
        }
    }
}

$ips = $db ? $db->query('SELECT * FROM ip_whitelist ORDER BY added_at DESC')->fetchAll() : [];
$clientIP = getClientIP();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IP Whitelist — MTRCB Admin</title>
    <link rel="stylesheet" href="/mtrcb_support/assets/style.css">
</head>
<body>
<nav class="navbar">
    <a href="/mtrcb_support/admin/index.php" class="navbar-brand">
        🎬 <span class="logo-text">MTRCB</span>&nbsp;Admin
    </a>
    <div class="navbar-nav">
        <a href="/mtrcb_support/admin/index.php"    class="nav-link">Dashboard</a>
        <a href="/mtrcb_support/admin/users.php"    class="nav-link">Users</a>
        <a href="/mtrcb_support/admin/uploads.php"  class="nav-link">Uploads</a>
        <a href="/mtrcb_support/admin/whitelist.php" class="nav-link active">IP Whitelist</a>
        <form method="POST" action="/mtrcb_support/logout.php" style="margin-left:8px">
            <button class="btn-logout">Sign Out</button>
        </form>
    </div>
</nav>

<div class="page-wrapper">
    <div class="page-header">
        <h1>IP Whitelist</h1>
        <p>Only IPs in this list can access the portal. Your current IP: <code style="color:var(--accent)"><?= e($clientIP) ?></code></p>
    </div>

    <?php if ($error):   ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

    <!-- Add IP -->
    <div class="card">
        <div class="card-title">Add IP Address</div>
        <form method="POST" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="add">
            <div class="form-group" style="flex:1;min-width:200px;margin-bottom:0">
                <label>IP Address</label>
                <input type="text" name="ip_address" placeholder="e.g. 192.168.1.100" required
                       pattern="^(\d{1,3}\.){3}\d{1,3}$|^[0-9a-fA-F:]+$">
            </div>
            <div class="form-group" style="flex:2;min-width:200px;margin-bottom:0">
                <label>Description</label>
                <input type="text" name="description" placeholder="e.g. Office network, MTRCB Main Building">
            </div>
            <button type="submit" class="btn btn-primary" style="white-space:nowrap">Add IP</button>
        </form>
    </div>

    <!-- Whitelist Table -->
    <div class="card">
        <div class="card-title">Whitelisted IPs (<?= count($ips) ?>)</div>
        <?php if (empty($ips)): ?>
            <p style="color:var(--text2);font-size:14px">No IPs in whitelist. Add your IP above first!</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>IP Address</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Added</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ips as $ip): ?>
                            <tr>
                                <td style="font-family:monospace;font-weight:600">
                                    <?= e($ip['ip_address']) ?>
                                    <?php if ($ip['ip_address'] === $clientIP): ?>
                                        <span class="badge badge-active" style="margin-left:6px;font-size:10px">YOU</span>
                                    <?php endif; ?>
                                </td>
                                <td style="color:var(--text2);font-size:13px"><?= e($ip['description'] ?: '—') ?></td>
                                <td>
                                    <span class="badge <?= $ip['active'] ? 'badge-active' : 'badge-inactive' ?>">
                                        <?= $ip['active'] ? 'Active' : 'Disabled' ?>
                                    </span>
                                </td>
                                <td style="color:var(--text2);font-size:13px"><?= e($ip['added_at']) ?></td>
                                <td>
                                    <div style="display:flex;gap:6px">
                                        <!-- Toggle -->
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int)$ip['id'] ?>">
                                            <button class="btn btn-ghost btn-sm" type="submit"
                                                    title="<?= $ip['active'] ? 'Disable' : 'Enable' ?>">
                                                <?= $ip['active'] ? '⏸ Disable' : '▶ Enable' ?>
                                            </button>
                                        </form>
                                        <!-- Delete -->
                                        <?php if ($ip['ip_address'] !== $clientIP): ?>
                                            <form method="POST" onsubmit="return confirm('Remove <?= e($ip['ip_address']) ?> from whitelist?')">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$ip['id'] ?>">
                                                <button class="btn btn-danger btn-sm" type="submit">🗑 Remove</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
