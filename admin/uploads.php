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
        $id = (int)($_POST['id'] ?? 0);

        if ($action === 'delete' && $id > 0) {
            $row = $db->prepare('SELECT filename, original_name FROM uploads WHERE id = ?');
            $row->execute([$id]);
            $f = $row->fetch();
            if ($f) {
                @unlink(UPLOAD_DIR . $f['filename']);
                $db->prepare('DELETE FROM uploads WHERE id = ?')->execute([$id]);
                $success = 'Deleted: ' . $f['original_name'];
            } else {
                $error = 'Upload not found.';
            }
        }
    }
}

// Pagination
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;

$total = $db ? (int)$db->query('SELECT COUNT(*) FROM uploads')->fetchColumn() : 0;
$pages = (int)ceil($total / $limit);

$uploads = $db ? $db->prepare(
    'SELECT u.*, us.username FROM uploads u
     JOIN users us ON u.user_id = us.id
     ORDER BY u.uploaded_at DESC LIMIT ? OFFSET ?'
) : null;
if ($uploads) {
    $uploads->bindValue(1, $limit, PDO::PARAM_INT);
    $uploads->bindValue(2, $offset, PDO::PARAM_INT);
    $uploads->execute();
    $uploads = $uploads->fetchAll();
} else {
    $uploads = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Uploads — MTRCB Admin</title>
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
        <a href="/mtrcb_support/admin/uploads.php"  class="nav-link active">Uploads</a>
        <a href="/mtrcb_support/admin/whitelist.php" class="nav-link">IP Whitelist</a>
        <form method="POST" action="/mtrcb_support/logout.php" style="margin-left:8px">
            <button class="btn-logout">Sign Out</button>
        </form>
    </div>
</nav>

<div class="page-wrapper">
    <div class="page-header">
        <h1>Manage Uploads</h1>
        <p>Total: <?= $total ?> file<?= $total !== 1 ? 's' : '' ?></p>
    </div>

    <?php if ($error):   ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Original Name</th>
                        <th>User</th>
                        <th>Size</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th>Uploaded</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($uploads)): ?>
                        <tr><td colspan="7" style="color:var(--text2);text-align:center;padding:32px">No uploads found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($uploads as $u): ?>
                        <tr>
                            <td style="font-family:monospace;font-size:13px;max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($u['original_name']) ?>">
                                <?= e($u['original_name']) ?>
                            </td>
                            <td><?= e($u['username']) ?></td>
                            <td style="white-space:nowrap"><?= formatBytes((int)$u['file_size']) ?></td>
                            <td style="color:var(--text2);font-size:12px"><?= e($u['mime_type'] ?: '—') ?></td>
                            <td style="color:var(--text2);font-size:13px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                <?= e($u['description'] ?: '—') ?>
                            </td>
                            <td style="color:var(--text2);font-size:13px;white-space:nowrap"><?= e($u['uploaded_at']) ?></td>
                            <td>
                                <form method="POST" onsubmit="return confirm('Delete this file permanently?')">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                    <button class="btn btn-danger btn-sm">🗑 Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <div style="display:flex;gap:8px;justify-content:center;margin-top:20px">
                <?php for ($p = 1; $p <= $pages; $p++): ?>
                    <a href="?page=<?= $p ?>" class="btn <?= $p === $page ? 'btn-primary' : 'btn-ghost' ?> btn-sm">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
