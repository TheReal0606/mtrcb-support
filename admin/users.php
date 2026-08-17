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
            $username  = trim($_POST['username'] ?? '');
            $password  = $_POST['password'] ?? '';
            $full_name = trim($_POST['full_name'] ?? '');
            $role      = in_array($_POST['role'] ?? '', ['admin', 'uploader', 'verifier']) ? $_POST['role'] : 'uploader';

            if (strlen($username) < 3 || strlen($username) > 50) {
                $error = 'Username must be 3–50 characters.';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters.';
            } else {
                try {
                    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                    $stmt = $db->prepare('INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, ?)');
                    $stmt->execute([$username, $hash, $full_name, $role]);
                    $success = "User '$username' created.";
                } catch (PDOException $e) {
                    $error = 'Username already exists.';
                }
            }

        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id === $user['id']) {
                $error = 'Cannot deactivate your own account.';
            } else {
                $db->prepare('UPDATE users SET active = 1 - active WHERE id = ?')->execute([$id]);
                $success = 'User status toggled.';
            }

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id === $user['id']) {
                $error = 'Cannot delete your own account.';
            } else {
                // Also delete their uploaded files
                $files = $db->prepare('SELECT filename FROM uploads WHERE user_id = ?');
                $files->execute([$id]);
                foreach ($files->fetchAll() as $f) {
                    @unlink(UPLOAD_DIR . $f['filename']);
                }
                $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                $success = 'User and all their uploads deleted.';
            }

        } elseif ($action === 'reset_pw') {
            $id  = (int)($_POST['id'] ?? 0);
            $pw  = $_POST['new_password'] ?? '';
            if (strlen($pw) < 8) {
                $error = 'Password must be at least 8 characters.';
            } else {
                $hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);
                $db->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$hash, $id]);
                $success = 'Password updated.';
            }
        }
    }
}

$users = $db ? $db->query(
    'SELECT u.*, (SELECT COUNT(*) FROM uploads WHERE user_id = u.id) AS upload_count FROM users u ORDER BY u.created_at DESC'
)->fetchAll() : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Users — MTRCB Admin</title>
    <link rel="stylesheet" href="/mtrcb_support/assets/style.css">
</head>
<body>
<nav class="navbar">
    <a href="/mtrcb_support/admin/index.php" class="navbar-brand">
        🎬 <span class="logo-text">MTRCB</span>&nbsp;Admin
    </a>
    <div class="navbar-nav">
        <a href="/mtrcb_support/admin/index.php"     class="nav-link">Dashboard</a>
        <a href="/mtrcb_support/admin/users.php"     class="nav-link active">Users</a>
        <a href="/mtrcb_support/admin/materials.php" class="nav-link">Materials</a>
        <a href="/mtrcb_support/admin/whitelist.php" class="nav-link">IP Whitelist</a>
        <form method="POST" action="/mtrcb_support/logout.php" style="margin-left:8px">
            <button class="btn-logout">Sign Out</button>
        </form>
    </div>
</nav>

<div class="page-wrapper">
    <div class="page-header">
        <h1>Manage Users</h1>
    </div>

    <?php if ($error):   ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

    <!-- Add User -->
    <div class="card">
        <div class="card-title">Add New User</div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="add">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px">
                <div class="form-group" style="margin-bottom:0">
                    <label>Username</label>
                    <input type="text" name="username" required minlength="3" maxlength="50">
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label>Full Name</label>
                    <input type="text" name="full_name">
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label>Password</label>
                    <input type="password" name="password" required minlength="8">
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label>Role</label>
                    <select name="role">
                        <option value="uploader">Uploader</option>
                        <option value="verifier">Verifier</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-primary" style="margin-top:16px">Add User</button>
        </form>
    </div>

    <!-- Users Table -->
    <div class="card">
        <div class="card-title">All Users (<?= count($users) ?>)</div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Full Name</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Uploads</th>
                        <th>Last Login</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td style="font-weight:600"><?= e($u['username']) ?></td>
                            <td style="color:var(--text2)"><?= e($u['full_name'] ?: '—') ?></td>
                            <td><span class="badge <?= $u['role'] === 'admin' ? 'badge-admin' : 'badge-user' ?>"><?= e(ucfirst($u['role'])) ?></span></td>
                            <td>
                                <span class="badge <?= $u['active'] ? 'badge-active' : 'badge-inactive' ?>">
                                    <?= $u['active'] ? 'Active' : 'Disabled' ?>
                                </span>
                            </td>
                            <td><?= (int)$u['upload_count'] ?></td>
                            <td style="color:var(--text2);font-size:13px"><?= $u['last_login'] ? e($u['last_login']) : '—' ?></td>
                            <td>
                                <div style="display:flex;gap:6px;flex-wrap:wrap">
                                    <?php if ((int)$u['id'] !== $user['id']): ?>
                                        <!-- Toggle active -->
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                            <button class="btn btn-ghost btn-sm">
                                                <?= $u['active'] ? '⏸' : '▶' ?>
                                            </button>
                                        </form>
                                        <!-- Reset password -->
                                        <button class="btn btn-ghost btn-sm"
                                            onclick="showResetForm(<?= (int)$u['id'] ?>, '<?= e($u['username']) ?>')">
                                            🔑 Reset PW
                                        </button>
                                        <!-- Delete -->
                                        <form method="POST" onsubmit="return confirm('Delete user <?= e($u['username']) ?> and all their uploads?')">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                            <button class="btn btn-danger btn-sm">🗑</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color:var(--text3);font-size:12px">(you)</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div id="reset-modal" style="display:none;position:fixed;inset:0;background:#00000088;z-index:200;align-items:center;justify-content:center">
    <div class="card" style="width:100%;max-width:380px;margin:0">
        <div class="card-title">Reset Password</div>
        <p id="reset-label" style="color:var(--text2);font-size:14px;margin-bottom:16px"></p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="reset_pw">
            <input type="hidden" name="id" id="reset-uid">
            <div class="form-group">
                <label>New Password</label>
                <input type="password" name="new_password" id="reset-pw" required minlength="8">
            </div>
            <div style="display:flex;gap:10px">
                <button type="submit" class="btn btn-primary">Save</button>
                <button type="button" class="btn btn-ghost" onclick="closeReset()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function showResetForm(id, username) {
    document.getElementById('reset-uid').value = id;
    document.getElementById('reset-label').textContent = 'Set new password for: ' + username;
    document.getElementById('reset-pw').value = '';
    const m = document.getElementById('reset-modal');
    m.style.display = 'flex';
}
function closeReset() {
    document.getElementById('reset-modal').style.display = 'none';
}
document.getElementById('reset-modal').addEventListener('click', function(e) {
    if (e.target === this) closeReset();
});
</script>
</body>
</html>
