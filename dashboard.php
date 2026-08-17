<?php
require_once __DIR__ . '/includes/ip_guard.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

startSecureSession();
requireLogin();

$user = currentUser();
$db   = getDB();

// Fetch user's uploads
$uploads = [];
if ($db) {
    $stmt = $db->prepare('SELECT * FROM uploads WHERE user_id = ? ORDER BY uploaded_at DESC');
    $stmt->execute([$user['id']]);
    $uploads = $stmt->fetchAll();
}

$csrf = generateCsrf();
$flash = flashHtml();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard — MTRCB Support Portal</title>
    <link rel="stylesheet" href="/mtrcb_support/assets/style.css">
</head>
<body>
<!-- Navbar -->
<nav class="navbar">
    <a href="/mtrcb_support/dashboard.php" class="navbar-brand">
        🎬 <span class="logo-text">MTRCB</span>&nbsp;Support
    </a>
    <div class="navbar-nav">
        <a href="/mtrcb_support/dashboard.php" class="nav-link active">Dashboard</a>
        <?php if ($user['role'] === 'admin'): ?>
            <a href="/mtrcb_support/admin/index.php" class="nav-link">
                Admin Panel <span class="nav-badge admin">Admin</span>
            </a>
        <?php endif; ?>
        <span style="color:var(--text3);font-size:13px;margin-left:12px">
            <?= e($user['full_name'] ?: $user['username']) ?>
        </span>
        <form method="POST" action="/mtrcb_support/logout.php" style="margin-left:8px">
            <button class="btn-logout">Sign Out</button>
        </form>
    </div>
</nav>

<div class="page-wrapper">
    <div class="page-header">
        <h1>Video Upload</h1>
        <p>Upload video files for MTRCB review processing.</p>
    </div>

    <?= $flash ?>

    <!-- Upload Card -->
    <div class="card">
        <div class="card-title">Upload a Video File</div>
        <form id="upload-form" method="POST" action="/mtrcb_support/upload.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

            <div class="drop-zone" id="drop-zone" onclick="document.getElementById('file-input').click()">
                <div class="dz-icon">🎞️</div>
                <p><strong>Click to browse</strong> or drag & drop a video file here</p>
                <p style="margin-top:8px;font-size:13px;color:var(--text3)">
                    Supported: MP4, AVI, MKV, MOV, WMV, FLV, WEBM, MPEG, 3GP, TS, M4V and more<br>
                    Max size: 2 GB
                </p>
                <div id="file-chosen" style="margin-top:12px;font-size:14px;color:var(--accent);display:none"></div>
            </div>
            <input type="file" id="file-input" name="video" accept="video/*,.mkv,.avi,.flv,.wmv,.m4v,.3gp,.ts,.mpeg,.mpg">

            <div class="form-group" style="margin-top:18px">
                <label for="description">Description (optional)</label>
                <textarea id="description" name="description" rows="2" placeholder="Brief description of the file…"></textarea>
            </div>

            <div class="progress-wrap" id="progress-wrap">
                <div class="progress-bar-bg"><div class="progress-bar" id="progress-bar"></div></div>
                <div class="progress-text" id="progress-text">0%</div>
            </div>

            <button type="submit" class="btn btn-primary" style="margin-top:16px" id="upload-btn" disabled>
                Upload File
            </button>
        </form>
    </div>

    <!-- Upload History -->
    <div class="card">
        <div class="card-title">My Uploads (<?= count($uploads) ?>)</div>
        <?php if (empty($uploads)): ?>
            <p style="color:var(--text2);font-size:14px">No uploads yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>File Name</th>
                            <th>Size</th>
                            <th>Uploaded</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($uploads as $u): ?>
                            <tr>
                                <td style="font-family:monospace;font-size:13px"><?= e($u['original_name']) ?></td>
                                <td><?= formatBytes((int)$u['file_size']) ?></td>
                                <td style="color:var(--text2);font-size:13px"><?= e($u['uploaded_at']) ?></td>
                                <td style="color:var(--text2);font-size:13px"><?= e($u['description'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
const dropZone  = document.getElementById('drop-zone');
const fileInput = document.getElementById('file-input');
const fileChosen= document.getElementById('file-chosen');
const uploadBtn = document.getElementById('upload-btn');
const form      = document.getElementById('upload-form');
const progressW = document.getElementById('progress-wrap');
const progressB = document.getElementById('progress-bar');
const progressT = document.getElementById('progress-text');

function onFileSelected(file) {
    if (!file) return;
    fileChosen.textContent = '📎 ' + file.name + ' (' + formatBytes(file.size) + ')';
    fileChosen.style.display = 'block';
    uploadBtn.disabled = false;
}

function formatBytes(b) {
    if (b >= 1073741824) return (b/1073741824).toFixed(2) + ' GB';
    if (b >= 1048576)    return (b/1048576).toFixed(2) + ' MB';
    if (b >= 1024)       return (b/1024).toFixed(2) + ' KB';
    return b + ' B';
}

fileInput.addEventListener('change', () => onFileSelected(fileInput.files[0]));

dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('drag-over');
    const file = e.dataTransfer.files[0];
    if (file) {
        const dt = new DataTransfer();
        dt.items.add(file);
        fileInput.files = dt.files;
        onFileSelected(file);
    }
});

form.addEventListener('submit', function(e) {
    e.preventDefault();
    if (!fileInput.files[0]) return;

    const fd = new FormData(form);
    const xhr = new XMLHttpRequest();
    xhr.open('POST', form.action, true);

    progressW.style.display = 'block';
    uploadBtn.disabled = true;
    uploadBtn.textContent = 'Uploading…';

    xhr.upload.addEventListener('progress', ev => {
        if (ev.lengthComputable) {
            const pct = Math.round((ev.loaded / ev.total) * 100);
            progressB.style.width = pct + '%';
            progressT.textContent = pct + '%';
        }
    });

    xhr.addEventListener('load', () => {
        try {
            const res = JSON.parse(xhr.responseText);
            if (res.ok) {
                window.location.href = '/mtrcb_support/dashboard.php?uploaded=1';
            } else {
                alert('Upload failed: ' + (res.error || 'Unknown error'));
                uploadBtn.disabled = false;
                uploadBtn.textContent = 'Upload File';
                progressW.style.display = 'none';
            }
        } catch(ex) {
            alert('Server error. Please try again.');
            uploadBtn.disabled = false;
            uploadBtn.textContent = 'Upload File';
        }
    });

    xhr.addEventListener('error', () => {
        alert('Network error. Please try again.');
        uploadBtn.disabled = false;
        uploadBtn.textContent = 'Upload File';
    });

    xhr.send(fd);
});

// Show flash from URL param
const params = new URLSearchParams(location.search);
if (params.get('uploaded') === '1') {
    const a = document.createElement('div');
    a.className = 'alert alert-success';
    a.textContent = 'File uploaded successfully.';
    document.querySelector('.page-wrapper').prepend(a);
    history.replaceState(null, '', '/mtrcb_support/dashboard.php');
}
</script>
</body>
</html>
