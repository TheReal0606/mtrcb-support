<?php
require_once dirname(__DIR__) . '/includes/ip_guard.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/config/db.php';

startSecureSession();
requireVerifier();

$user = currentUser();
$db   = getDB();

// Filter
$filter  = $_GET['status'] ?? 'all';
$search  = trim($_GET['q'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$limit   = 30;
$offset  = ($page - 1) * $limit;

$where  = [];
$params = [];

if ($filter === 'pending')  { $where[] = 'm.status = "pending"'; }
if ($filter === 'uploaded') { $where[] = 'm.status = "uploaded"'; }
if ($search !== '') {
    $where[] = '(m.application_number LIKE ? OR m.title LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int)$db->prepare("SELECT COUNT(*) FROM materials m $whereSQL")->execute($params) ?
         (function() use ($db, $whereSQL, $params) {
             $s = $db->prepare("SELECT COUNT(*) FROM materials m $whereSQL");
             $s->execute($params); return (int)$s->fetchColumn();
         })() : 0;

$sql = "SELECT m.*, u.username, u.full_name,
        (SELECT COUNT(*) FROM material_files WHERE material_id = m.id) AS file_count
        FROM materials m JOIN users u ON m.submitted_by = u.id
        $whereSQL ORDER BY m.created_at DESC LIMIT ? OFFSET ?";

$stmt = $db->prepare($sql);
$i = 1;
foreach ($params as $p) { $stmt->bindValue($i++, $p); }
$stmt->bindValue($i++, $limit, PDO::PARAM_INT);
$stmt->bindValue($i,   $offset, PDO::PARAM_INT);
$stmt->execute();
$materials = $stmt->fetchAll();

$pages = max(1, (int)ceil($total / $limit));

// Stats
$stats = $db->query('SELECT status, COUNT(*) c FROM materials GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$totalAll     = array_sum($stats);
$totalPending = $stats['pending']  ?? 0;
$totalUploaded= $stats['uploaded'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify Materials — MTRCB Support</title>
    <link rel="stylesheet" href="/mtrcb_support/assets/style.css">
    <style>
        .filter-tabs { display:flex; gap:6px; margin-bottom:20px; flex-wrap:wrap; }
        .filter-tab { padding:6px 16px; border-radius:999px; border:1px solid var(--border); background:var(--bg3); color:var(--text2); font-size:13px; font-weight:600; text-decoration:none; }
        .filter-tab.active { background:var(--accent); border-color:var(--accent); color:#fff; }
        .filter-tab:hover { text-decoration:none; opacity:.85; }
    </style>
</head>
<body>
<nav class="navbar">
    <a href="/mtrcb_support/verifier/index.php" class="navbar-brand">
        🎬 <span class="logo-text">MTRCB</span>&nbsp;Verifier
    </a>
    <div class="navbar-nav">
        <a href="/mtrcb_support/verifier/index.php" class="nav-link active">Materials</a>
        <?php if ($user['role'] === 'admin'): ?>
            <a href="/mtrcb_support/admin/index.php" class="nav-link">Admin <span class="nav-badge admin">Admin</span></a>
        <?php endif; ?>
        <span style="color:var(--text3);font-size:13px;margin-left:12px"><?= e($user['full_name'] ?: $user['username']) ?></span>
        <form method="POST" action="/mtrcb_support/logout.php" style="margin-left:8px">
            <button class="btn-logout">Sign Out</button>
        </form>
    </div>
</nav>

<div class="page-wrapper">
    <div class="page-header">
        <h1>Material Verification</h1>
        <p>View submitted materials and their upload status.</p>
    </div>

    <!-- Stats -->
    <div class="stats-grid" style="grid-template-columns:repeat(3,1fr);max-width:500px">
        <div class="stat-card">
            <div class="stat-label">Total</div>
            <div class="stat-value"><?= $totalAll ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Pending</div>
            <div class="stat-value" style="color:#f87171"><?= $totalPending ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Uploaded</div>
            <div class="stat-value" style="color:#4ade80"><?= $totalUploaded ?></div>
        </div>
    </div>

    <div class="card">
        <!-- Search + Filters -->
        <form method="GET" style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;align-items:center">
            <input type="hidden" name="status" value="<?= e($filter) ?>">
            <input type="text" name="q" value="<?= e($search) ?>"
                   placeholder="Search application # or title…"
                   style="flex:1;min-width:220px">
            <button class="btn btn-ghost btn-sm" type="submit">Search</button>
            <?php if ($search): ?>
                <a href="?" class="btn btn-ghost btn-sm">Clear</a>
            <?php endif; ?>
        </form>

        <div class="filter-tabs">
            <?php
            $tabs = ['all' => 'All (' . $totalAll . ')', 'pending' => 'Pending (' . $totalPending . ')', 'uploaded' => 'Uploaded (' . $totalUploaded . ')'];
            foreach ($tabs as $k => $label):
                $href = '?status=' . $k . ($search ? '&q=' . urlencode($search) : '');
            ?>
                <a href="<?= e($href) ?>" class="filter-tab <?= $filter === $k ? 'active' : '' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>

        <!-- Table -->
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Application #</th>
                        <th>Title</th>
                        <th>Submitted By</th>
                        <th>Files</th>
                        <th>Status</th>
                        <th>Date Submitted</th>
                        <th>Last Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($materials)): ?>
                        <tr><td colspan="7" style="text-align:center;color:var(--text3);padding:32px">No materials found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($materials as $m): ?>
                        <tr>
                            <td style="font-family:monospace;font-weight:700;color:var(--accent)"><?= e($m['application_number']) ?></td>
                            <td style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($m['title']) ?>">
                                <?= e($m['title']) ?>
                            </td>
                            <td style="color:var(--text2)"><?= e($m['full_name'] ?: $m['username']) ?></td>
                            <td style="text-align:center">
                                <span style="background:var(--bg3);padding:2px 10px;border-radius:999px;font-size:13px;font-weight:700">
                                    <?= (int)$m['file_count'] ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($m['status'] === 'uploaded'): ?>
                                    <span class="badge badge-active">✓ Uploaded</span>
                                <?php else: ?>
                                    <span class="badge badge-inactive">⏳ Pending</span>
                                <?php endif; ?>
                            </td>
                            <td style="color:var(--text2);font-size:13px;white-space:nowrap"><?= e($m['created_at']) ?></td>
                            <td style="color:var(--text2);font-size:13px;white-space:nowrap"><?= e($m['updated_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($pages > 1): ?>
            <div style="display:flex;gap:8px;justify-content:center;margin-top:20px;flex-wrap:wrap">
                <?php for ($p = 1; $p <= $pages; $p++):
                    $href = '?status=' . urlencode($filter) . '&page=' . $p . ($search ? '&q=' . urlencode($search) : '');
                ?>
                    <a href="<?= e($href) ?>" class="btn <?= $p === $page ? 'btn-primary' : 'btn-ghost' ?> btn-sm">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>

        <div style="color:var(--text3);font-size:12px;margin-top:12px;text-align:right">
            Showing <?= count($materials) ?> of <?= $total ?> material<?= $total !== 1 ? 's' : '' ?>
        </div>
    </div>
</div>

<script>
// Auto-refresh every 30 seconds so verifier sees new uploads without manual reload
setInterval(() => location.reload(), 30000);
</script>
</body>
</html>
