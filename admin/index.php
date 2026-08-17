<?php
require_once dirname(__DIR__) . '/includes/ip_guard.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/config/db.php';

startSecureSession();
requireAdmin();

$user = currentUser();
$db   = getDB();

$stats = ['users' => 0, 'materials' => 0, 'files' => 0, 'total_size' => 0, 'whitelist' => 0, 'pending' => 0, 'uploaded' => 0];
$recentMaterials = [];
if ($db) {
    $stats['users']      = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $stats['materials']  = (int)$db->query('SELECT COUNT(*) FROM materials')->fetchColumn();
    $stats['files']      = (int)$db->query('SELECT COUNT(*) FROM material_files')->fetchColumn();
    $stats['total_size'] = (int)$db->query('SELECT COALESCE(SUM(file_size),0) FROM material_files')->fetchColumn();
    $stats['whitelist']  = (int)$db->query('SELECT COUNT(*) FROM ip_whitelist WHERE active = 1')->fetchColumn();
    $stats['pending']    = (int)$db->query('SELECT COUNT(*) FROM materials WHERE status = "pending"')->fetchColumn();
    $stats['uploaded']   = (int)$db->query('SELECT COUNT(*) FROM materials WHERE status = "uploaded"')->fetchColumn();

    $recentMaterials = $db->query(
        'SELECT m.*, u.username,
         (SELECT COUNT(*) FROM material_files WHERE material_id = m.id) AS file_count
         FROM materials m JOIN users u ON m.submitted_by = u.id
         ORDER BY m.created_at DESC LIMIT 10'
    )->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin — MTRCB Support</title>
    <link rel="stylesheet" href="/mtrcb_support/assets/style.css">
</head>
<body>
<nav class="navbar">
    <a href="/mtrcb_support/admin/index.php" class="navbar-brand">
        🎬 <span class="logo-text">MTRCB</span>&nbsp;Admin
    </a>
    <div class="navbar-nav">
        <a href="/mtrcb_support/admin/index.php"     class="nav-link active">Dashboard</a>
        <a href="/mtrcb_support/admin/users.php"     class="nav-link">Users</a>
        <a href="/mtrcb_support/admin/materials.php" class="nav-link">Materials</a>
        <a href="/mtrcb_support/admin/whitelist.php" class="nav-link">IP Whitelist</a>
        <form method="POST" action="/mtrcb_support/logout.php" style="margin-left:8px">
            <button class="btn-logout">Sign Out</button>
        </form>
    </div>
</nav>

<div class="page-wrapper">
    <div class="page-header">
        <h1>Admin Dashboard</h1>
        <p>System overview — MTRCB Support Portal</p>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total Users</div>
            <div class="stat-value"><?= $stats['users'] ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Materials</div>
            <div class="stat-value"><?= $stats['materials'] ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Pending</div>
            <div class="stat-value" style="color:#f87171"><?= $stats['pending'] ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Uploaded</div>
            <div class="stat-value" style="color:#4ade80"><?= $stats['uploaded'] ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Files</div>
            <div class="stat-value"><?= $stats['files'] ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Storage Used</div>
            <div class="stat-value" style="font-size:20px"><?= formatBytes($stats['total_size']) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Whitelisted IPs</div>
            <div class="stat-value"><?= $stats['whitelist'] ?></div>
        </div>
    </div>

    <div class="card">
        <div class="card-title">Recent Submissions</div>
        <?php if (empty($recentMaterials)): ?>
            <p style="color:var(--text2);font-size:14px">No materials submitted yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Application #</th><th>Title</th><th>By</th><th>Files</th><th>Status</th><th>Date</th></tr></thead>
                    <tbody>
                        <?php foreach ($recentMaterials as $m): ?>
                            <tr>
                                <td style="font-family:monospace;font-weight:700"><?= e($m['application_number']) ?></td>
                                <td><?= e($m['title']) ?></td>
                                <td style="color:var(--text2)"><?= e($m['username']) ?></td>
                                <td><?= (int)$m['file_count'] ?></td>
                                <td><span class="badge <?= $m['status'] === 'uploaded' ? 'badge-active' : 'badge-inactive' ?>"><?= $m['status'] === 'uploaded' ? 'Uploaded' : 'Pending' ?></span></td>
                                <td style="color:var(--text2);font-size:13px"><?= e($m['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px">
        <a href="/mtrcb_support/admin/users.php"     class="btn btn-ghost" style="padding:16px 20px;border-radius:10px;flex-direction:column;gap:4px;display:flex"><span style="font-size:24px">👥</span><span style="font-weight:700">Users</span></a>
        <a href="/mtrcb_support/admin/materials.php" class="btn btn-ghost" style="padding:16px 20px;border-radius:10px;flex-direction:column;gap:4px;display:flex"><span style="font-size:24px">📋</span><span style="font-weight:700">Materials</span></a>
        <a href="/mtrcb_support/admin/whitelist.php" class="btn btn-ghost" style="padding:16px 20px;border-radius:10px;flex-direction:column;gap:4px;display:flex"><span style="font-size:24px">🛡️</span><span style="font-weight:700">IP Whitelist</span></a>
        <a href="/mtrcb_support/verifier/index.php"  class="btn btn-ghost" style="padding:16px 20px;border-radius:10px;flex-direction:column;gap:4px;display:flex"><span style="font-size:24px">✅</span><span style="font-weight:700">Verifier View</span></a>
    </div>
</div>
</body>
</html>
