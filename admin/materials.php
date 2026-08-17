<?php
require_once dirname(__DIR__) . '/includes/ip_guard.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/config/db.php';

startSecureSession();
requireAdmin();

$db   = getDB();
$csrf = generateCsrf();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? '';
        $id     = (int)($_POST['id'] ?? 0);

        if ($action === 'delete' && $id > 0) {
            // Only delete physical file if no other material shares it
            $files = $db->prepare('SELECT filename FROM material_files WHERE material_id = ?');
            $files->execute([$id]);
            foreach ($files->fetchAll() as $f) {
                $refs = $db->prepare('SELECT COUNT(*) FROM material_files WHERE filename = ? AND material_id != ?');
                $refs->execute([$f['filename'], $id]);
                if ((int)$refs->fetchColumn() === 0) {
                    @unlink(UPLOAD_DIR . $f['filename']);
                }
            }
            $db->prepare('DELETE FROM materials WHERE id = ?')->execute([$id]);
            $success = 'Material deleted.';
        }
    }
}

$filter = $_GET['status'] ?? 'all';
$search = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 25;
$offset = ($page - 1) * $limit;

$where = [];
$params = [];
if ($filter === 'pending')  { $where[] = 'm.status = "pending"'; }
if ($filter === 'uploaded') { $where[] = 'm.status = "uploaded"'; }
if ($search !== '') {
    $where[] = '(m.application_number LIKE ? OR m.title LIKE ? OR u.username LIKE ?)';
    $params = array_merge($params, ['%'.$search.'%', '%'.$search.'%', '%'.$search.'%']);
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $db->prepare("SELECT COUNT(*) FROM materials m JOIN users u ON m.submitted_by = u.id $whereSQL");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $limit));

$sql = "SELECT m.*, u.username, u.full_name,
        (SELECT COUNT(*) FROM material_files WHERE material_id = m.id) AS file_count,
        (SELECT COALESCE(SUM(file_size),0) FROM material_files WHERE material_id = m.id) AS total_size
        FROM materials m JOIN users u ON m.submitted_by = u.id
        $whereSQL ORDER BY m.created_at DESC LIMIT ? OFFSET ?";
$stmt = $db->prepare($sql);
$i = 1;
foreach ($params as $p) { $stmt->bindValue($i++, $p); }
$stmt->bindValue($i++, $limit,  PDO::PARAM_INT);
$stmt->bindValue($i,   $offset, PDO::PARAM_INT);
$stmt->execute();
$materials = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Materials — MTRCB Admin</title>
    <link rel="stylesheet" href="/mtrcb_support/assets/style.css">
    <style>
        .filter-tabs { display:flex; gap:6px; margin-bottom:16px; flex-wrap:wrap; }
        .filter-tab { padding:5px 14px; border-radius:999px; border:1px solid var(--border); background:var(--bg3); color:var(--text2); font-size:13px; font-weight:600; text-decoration:none; }
        .filter-tab.active { background:var(--accent); border-color:var(--accent); color:#fff; }
    </style>
</head>
<body>
<nav class="navbar">
    <a href="/mtrcb_support/admin/index.php" class="navbar-brand">
        🎬 <span class="logo-text">MTRCB</span>&nbsp;Admin
    </a>
    <div class="navbar-nav">
        <a href="/mtrcb_support/admin/index.php"     class="nav-link">Dashboard</a>
        <a href="/mtrcb_support/admin/users.php"     class="nav-link">Users</a>
        <a href="/mtrcb_support/admin/materials.php" class="nav-link active">Materials</a>
        <a href="/mtrcb_support/admin/whitelist.php" class="nav-link">IP Whitelist</a>
        <form method="POST" action="/mtrcb_support/logout.php" style="margin-left:8px">
            <button class="btn-logout">Sign Out</button>
        </form>
    </div>
</nav>

<div class="page-wrapper">
    <div class="page-header">
        <h1>All Materials</h1>
        <p>Total: <?= $total ?> submission<?= $total !== 1 ? 's' : '' ?></p>
    </div>

    <?php if ($error):   ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

    <div class="card">
        <form method="GET" style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;align-items:center">
            <input type="hidden" name="status" value="<?= e($filter) ?>">
            <input type="text" name="q" value="<?= e($search) ?>"
                   placeholder="Search app #, title, or user…"
                   style="flex:1;min-width:220px">
            <button class="btn btn-ghost btn-sm" type="submit">Search</button>
            <?php if ($search): ?><a href="?status=<?= e($filter) ?>" class="btn btn-ghost btn-sm">Clear</a><?php endif; ?>
        </form>

        <div class="filter-tabs">
            <?php foreach (['all' => 'All', 'pending' => 'Pending', 'uploaded' => 'Uploaded'] as $k => $label): ?>
                <a href="?status=<?= $k ?><?= $search ? '&q='.urlencode($search) : '' ?>"
                   class="filter-tab <?= $filter === $k ? 'active' : '' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Application #</th>
                        <th>Title</th>
                        <th>Submitted By</th>
                        <th>Files</th>
                        <th>Size</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($materials)): ?>
                        <tr><td colspan="8" style="text-align:center;color:var(--text3);padding:32px">No materials found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($materials as $m): ?>
                        <tr>
                            <td style="font-family:monospace;font-weight:700;color:var(--accent)"><?= e($m['application_number']) ?></td>
                            <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($m['title']) ?>"><?= e($m['title']) ?></td>
                            <td style="color:var(--text2)"><?= e($m['full_name'] ?: $m['username']) ?></td>
                            <td style="text-align:center"><?= (int)$m['file_count'] ?></td>
                            <td style="font-size:13px"><?= formatBytes((int)$m['total_size']) ?></td>
                            <td><span class="badge <?= $m['status'] === 'uploaded' ? 'badge-active' : 'badge-inactive' ?>"><?= $m['status'] === 'uploaded' ? 'Uploaded' : 'Pending' ?></span></td>
                            <td style="color:var(--text2);font-size:13px;white-space:nowrap"><?= e($m['created_at']) ?></td>
                            <td>
                                <form method="POST" onsubmit="return confirm('Delete material &quot;<?= e($m['application_number']) ?>&quot; and all its files?')">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                    <button class="btn btn-danger btn-sm">🗑 Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <div style="display:flex;gap:8px;justify-content:center;margin-top:20px;flex-wrap:wrap">
                <?php for ($p = 1; $p <= $pages; $p++): ?>
                    <a href="?status=<?= urlencode($filter) ?>&page=<?= $p ?><?= $search ? '&q='.urlencode($search) : '' ?>"
                       class="btn <?= $p === $page ? 'btn-primary' : 'btn-ghost' ?> btn-sm"><?= $p ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
