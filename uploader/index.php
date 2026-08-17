<?php
require_once dirname(__DIR__) . '/includes/ip_guard.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/config/db.php';

startSecureSession();
requireUploader();

$user    = currentUser();
$db      = getDB();
$csrf    = generateCsrf();
$isAdmin = $user['role'] === 'admin';

if ($isAdmin) {
    $stmt = $db->query(
        'SELECT m.*, u.username,
         (SELECT COUNT(*) FROM material_files WHERE material_id = m.id) AS file_count
         FROM materials m JOIN users u ON m.submitted_by = u.id
         ORDER BY m.created_at DESC LIMIT 50'
    );
} else {
    $stmt = $db->prepare(
        'SELECT m.*, u.username,
         (SELECT COUNT(*) FROM material_files WHERE material_id = m.id) AS file_count
         FROM materials m JOIN users u ON m.submitted_by = u.id
         WHERE m.submitted_by = ? ORDER BY m.created_at DESC LIMIT 50'
    );
    $stmt->execute([$user['id']]);
}
$materials = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Upload Materials — MTRCB Support</title>
    <link rel="stylesheet" href="/mtrcb_support/assets/style.css">
    <style>
        /* App rows */
        .app-rows { display:flex; flex-direction:column; gap:10px; margin-bottom:4px; }
        .app-row  {
            display:grid;
            grid-template-columns: 200px 1fr auto;
            gap:10px; align-items:end;
        }
        .app-row.single { grid-template-columns: 200px 1fr; }
        .app-row .form-group { margin-bottom:0; }
        .btn-remove-row {
            background:none; border:1px solid var(--border); border-radius:8px;
            color:var(--text3); cursor:pointer; padding:9px 12px; font-size:16px;
            line-height:1; transition:background .15s,color .15s,border-color .15s;
            margin-bottom:1px;
        }
        .btn-remove-row:hover { background:var(--danger); border-color:var(--danger); color:#fff; }

        /* Checkbox toggle */
        .multi-toggle {
            display:flex; align-items:center; gap:10px;
            padding:14px 18px; background:var(--bg3);
            border:1px solid var(--border); border-radius:10px;
            cursor:pointer; user-select:none; margin-bottom:20px;
        }
        .multi-toggle input[type=checkbox] { width:18px; height:18px; accent-color:var(--accent); cursor:pointer; }
        .multi-toggle-label { font-weight:600; font-size:14px; }
        .multi-toggle-desc  { font-size:12px; color:var(--text2); margin-top:2px; }

        /* Drop zone */
        .drop-zone {
            border:2px dashed var(--border); border-radius:10px;
            padding:28px 16px; text-align:center; cursor:pointer;
            transition:border-color .2s,background .2s;
        }
        .drop-zone:hover,.drop-zone.drag-over { border-color:var(--accent); background:#1e3a5f22; }

        /* Queue */
        .queue-item {
            display:grid;
            grid-template-columns: 1fr 90px 110px 28px;
            align-items:center; gap:10px;
            padding:10px 14px; background:var(--bg3);
            border:1px solid var(--border); border-radius:8px; margin-bottom:6px;
        }
        .file-name { font-size:13px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .file-size { font-size:12px; color:var(--text2); }
        .file-progress { margin-top:4px; }
        .q-status { font-size:12px; font-weight:700; text-align:center; }
        .q-status.pending   { color:var(--text3); }
        .q-status.uploading { color:#fbbf24; }
        .q-status.done      { color:#4ade80; }
        .q-status.error     { color:#f87171; }
        .q-remove { background:none; border:none; color:var(--text3); cursor:pointer; font-size:17px; padding:0; line-height:1; }
        .q-remove:hover { color:var(--danger); }

        .overall-bar  { background:var(--bg3); border-radius:999px; height:8px; overflow:hidden; margin:6px 0 4px; }
        .overall-fill { height:8px; background:linear-gradient(90deg,#2563eb,#60a5fa); border-radius:999px; transition:width .3s; width:0%; }
    </style>
</head>
<body>
<nav class="navbar">
    <a href="/mtrcb_support/uploader/index.php" class="navbar-brand">
        🎬 <span class="logo-text">MTRCB</span>&nbsp;Support
    </a>
    <div class="navbar-nav">
        <a href="/mtrcb_support/uploader/index.php" class="nav-link active">Upload</a>
        <?php if ($isAdmin): ?>
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
        <h1>Upload Material</h1>
        <p>Each application number requires a separate submission record.</p>
    </div>

    <div class="card">

        <!-- Multi-application toggle -->
        <label class="multi-toggle" for="multi-check">
            <input type="checkbox" id="multi-check" onchange="toggleMultiMode()">
            <div>
                <div class="multi-toggle-label">One Material for Multiple Application Numbers</div>
                <div class="multi-toggle-desc">Same files will be linked to all listed application numbers.</div>
            </div>
        </label>

        <!-- Application rows -->
        <div class="app-rows" id="app-rows">
            <!-- Rendered by JS -->
        </div>

        <div id="add-row-wrap" style="display:none;margin-bottom:20px">
            <button class="btn btn-ghost btn-sm" type="button" onclick="addRow()">+ Add Another Application Number</button>
        </div>

        <hr style="border:none;border-top:1px solid var(--border);margin:20px 0">

        <!-- Drop zone -->
        <div class="drop-zone" id="drop-zone" onclick="document.getElementById('file-picker').click()">
            <div style="font-size:36px;margin-bottom:8px">🎞️</div>
            <p style="font-size:14px"><strong>Click to browse</strong> or drag & drop video files here</p>
            <p style="font-size:12px;color:var(--text3);margin-top:6px">
                MP4, AVI, MKV, MOV, WMV, FLV, WEBM and more &middot; Max 500 MB per file
            </p>
        </div>
        <input type="file" id="file-picker" multiple
               accept="video/*,.mkv,.avi,.flv,.wmv,.m4v,.3gp,.ts,.mpeg,.mpg"
               style="display:none">

        <!-- File queue -->
        <div id="queue-list" style="margin-top:12px"></div>

        <!-- Overall progress -->
        <div id="overall-wrap" style="display:none;margin-top:12px">
            <div style="display:flex;justify-content:space-between;font-size:13px;color:var(--text2)">
                <span id="overall-label">Uploading…</span>
                <span id="overall-pct">0%</span>
            </div>
            <div class="overall-bar"><div class="overall-fill" id="overall-fill"></div></div>
        </div>

        <div id="upload-success" class="alert alert-success" style="display:none;margin-top:14px"></div>
        <div id="upload-error"   class="alert alert-error"   style="display:none;margin-top:14px"></div>

        <!-- Actions -->
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:16px">
            <button class="btn btn-ghost" type="button" onclick="document.getElementById('file-picker').click()">+ Add Files</button>
            <button class="btn btn-primary" id="upload-btn" type="button" onclick="startUpload()" disabled>⬆ Upload</button>
            <span id="queue-count" style="color:var(--text2);font-size:13px"></span>
        </div>
    </div>

    <!-- Submission History -->
    <div class="card">
        <div class="card-title"><?= $isAdmin ? 'All Submissions' : 'My Submissions' ?> (<?= count($materials) ?>)</div>
        <?php if (empty($materials)): ?>
            <p style="color:var(--text2);font-size:14px">No submissions yet.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Application #</th>
                        <th>Title</th>
                        <?php if ($isAdmin): ?><th>Uploaded By</th><?php endif; ?>
                        <th>Files</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($materials as $m): ?>
                        <tr>
                            <td style="font-family:monospace;font-weight:700;color:var(--accent)"><?= e($m['application_number']) ?></td>
                            <td><?= e($m['title']) ?></td>
                            <?php if ($isAdmin): ?><td style="color:var(--text2)"><?= e($m['username']) ?></td><?php endif; ?>
                            <td><?= (int)$m['file_count'] ?></td>
                            <td>
                                <span class="badge <?= $m['status'] === 'uploaded' ? 'badge-active' : 'badge-inactive' ?>">
                                    <?= $m['status'] === 'uploaded' ? '✓ Uploaded' : '⏳ Pending' ?>
                                </span>
                            </td>
                            <td style="color:var(--text2);font-size:13px;white-space:nowrap"><?= e($m['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
const CSRF  = <?= json_encode($csrf) ?>;
const MAX_B = 500 * 1024 * 1024;

let multiMode = false;
let rows      = [{ id: 0, appNum: '', title: '' }];
let nextRowId = 1;

let queue  = [];
let nextId = 0;

const picker      = document.getElementById('file-picker');
const dropZone    = document.getElementById('drop-zone');
const queueList   = document.getElementById('queue-list');
const uploadBtn   = document.getElementById('upload-btn');
const queueCount  = document.getElementById('queue-count');
const overallWrap = document.getElementById('overall-wrap');
const overallFill = document.getElementById('overall-fill');
const overallLabel= document.getElementById('overall-label');
const overallPct  = document.getElementById('overall-pct');
const successBox  = document.getElementById('upload-success');
const errorBox    = document.getElementById('upload-error');
const addRowWrap  = document.getElementById('add-row-wrap');
const appRowsDiv  = document.getElementById('app-rows');

// ── Initial render ──────────────────────────────────────────────
function toggleMultiMode() {
    multiMode = document.getElementById('multi-check').checked;
    if (!multiMode) {
        // Keep only first row
        rows = [rows[0]];
    }
    renderRows();
}

function renderRows() {
    appRowsDiv.innerHTML = '';
    rows.forEach((row, idx) => {
        const div = document.createElement('div');
        div.className = 'app-row' + (multiMode ? '' : ' single');
        div.id = 'row-' + row.id;

        div.innerHTML = `
            <div class="form-group">
                <label>Application Number <span style="color:#f87171">*</span></label>
                <input type="text" id="appnum-${row.id}" placeholder="e.g. APP-2026-000${idx+1}"
                       maxlength="100" value="${esc(row.appNum)}"
                       oninput="saveRow(${row.id})">
            </div>
            <div class="form-group">
                <label>Title of Material <span style="color:#f87171">*</span></label>
                <input type="text" id="title-${row.id}" placeholder="e.g. Film Title"
                       maxlength="255" value="${esc(row.title)}"
                       oninput="saveRow(${row.id}); ${idx===0 ? 'syncTitles()' : ''}">
            </div>
            ${multiMode && idx > 0 ? `<button class="btn-remove-row" type="button" onclick="removeRow(${row.id})" title="Remove">✕</button>` : (multiMode ? '<div></div>' : '')}
        `;
        appRowsDiv.appendChild(div);
    });

    addRowWrap.style.display = multiMode ? '' : 'none';
}

function saveRow(id) {
    const row = rows.find(r => r.id === id);
    if (!row) return;
    row.appNum = document.getElementById('appnum-' + id)?.value || '';
    row.title  = document.getElementById('title-'  + id)?.value || '';
}

function syncTitles() {
    // Copy first row title to all other rows
    if (!multiMode) return;
    const firstTitle = document.getElementById('title-' + rows[0].id)?.value || '';
    rows.slice(1).forEach(r => {
        const el = document.getElementById('title-' + r.id);
        if (el) { el.value = firstTitle; r.title = firstTitle; }
    });
}

function addRow() {
    saveRow(rows[0].id);
    const firstTitle = rows[0].title;
    rows.push({ id: nextRowId++, appNum: '', title: firstTitle });
    renderRows();
    // Restore values (renderRows clears them)
    rows.forEach(r => {
        const an = document.getElementById('appnum-' + r.id);
        const ti = document.getElementById('title-'  + r.id);
        if (an) an.value = r.appNum;
        if (ti) ti.value = r.title;
    });
}

function removeRow(id) {
    if (rows.length <= 1) return;
    saveRow(id);
    rows = rows.filter(r => r.id !== id);
    renderRows();
    rows.forEach(r => {
        const an = document.getElementById('appnum-' + r.id);
        const ti = document.getElementById('title-'  + r.id);
        if (an) an.value = r.appNum;
        if (ti) ti.value = r.title;
    });
}

// ── File queue ───────────────────────────────────────────────────
picker.addEventListener('change', () => { addFiles(Array.from(picker.files)); picker.value = ''; });

dropZone.addEventListener('dragover',  e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
dropZone.addEventListener('drop', e => {
    e.preventDefault(); dropZone.classList.remove('drag-over');
    addFiles(Array.from(e.dataTransfer.files));
});

function addFiles(files) {
    files.forEach(f => {
        if (f.size > MAX_B) { alert(f.name + ' exceeds 500 MB and was skipped.'); return; }
        const item = { file: f, id: nextId++, status: 'pending' };
        queue.push(item);
        renderItem(item);
    });
    updateUI();
}

function renderItem(item) {
    const div = document.createElement('div');
    div.className = 'queue-item';
    div.id = 'qi-' + item.id;
    div.innerHTML = `
        <div>
            <div class="file-name" title="${esc(item.file.name)}">${esc(item.file.name)}</div>
            <div class="file-size">${fmtBytes(item.file.size)}</div>
            <div class="file-progress">
                <div class="progress-bar-bg" style="height:4px">
                    <div class="progress-bar" id="pb-${item.id}" style="width:0%;height:4px"></div>
                </div>
            </div>
        </div>
        <div class="file-size" style="text-align:right;white-space:nowrap">${fmtBytes(item.file.size)}</div>
        <div class="q-status pending" id="qs-${item.id}">Pending</div>
        <button class="q-remove" onclick="removeItem(${item.id})">✕</button>`;
    queueList.appendChild(div);
}

function removeItem(id) {
    queue = queue.filter(i => i.id !== id);
    document.getElementById('qi-' + id)?.remove();
    updateUI();
}

function updateUI() {
    const n = queue.length;
    queueCount.textContent = n > 0 ? n + ' file' + (n > 1 ? 's' : '') + ' queued' : '';
    uploadBtn.disabled = n === 0;
}

function setStatus(id, status, pct) {
    const s   = document.getElementById('qs-' + id);
    const p   = document.getElementById('pb-' + id);
    const btn = document.querySelector('#qi-' + id + ' .q-remove');
    if (s) { s.className = 'q-status '+status; s.textContent = {pending:'Pending',uploading:'Uploading…',done:'✓ Done',error:'✗ Error'}[status]||status; }
    if (p && pct !== undefined) p.style.width = pct + '%';
    if (btn) btn.style.display = status === 'pending' ? '' : 'none';
}

// ── Upload ───────────────────────────────────────────────────────
async function startUpload() {
    // Sync values from DOM
    rows.forEach(r => saveRow(r.id));

    for (const row of rows) {
        if (!row.appNum.trim()) { alert('Enter Application Number for all rows.'); return; }
        if (!row.title.trim())  { alert('Enter Title for all rows.'); return; }
    }

    const pending = queue.filter(i => i.status === 'pending');
    if (!pending.length) { alert('Add at least one file.'); return; }

    uploadBtn.disabled = true;
    successBox.style.display = 'none';
    errorBox.style.display   = 'none';
    overallWrap.style.display = 'block';

    // Create material record(s)
    let materialIds;
    try {
        const res = await fetch('/mtrcb_support/uploader/submit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                csrf_token: CSRF,
                rows: JSON.stringify(rows.map(r => ({ application_number: r.appNum.trim(), title: r.title.trim() })))
            })
        });
        const json = await res.json();
        if (!json.ok) { showError('Cannot create submission: ' + (json.error || 'Unknown error')); return; }
        materialIds = json.material_ids;
    } catch(e) { showError('Network error. Try again.'); return; }

    // Upload files one by one — each file linked to ALL material IDs
    let done = 0;
    for (const item of pending) {
        setStatus(item.id, 'uploading', 0);
        overallLabel.textContent = 'File ' + (done+1) + ' of ' + pending.length + ': ' + item.file.name;

        const ok = await uploadOne(item, materialIds, done, pending.length);
        setStatus(item.id, ok ? 'done' : 'error', ok ? 100 : 0);
        item.status = ok ? 'done' : 'error';
        done++;
        const pct = Math.round(done / pending.length * 100);
        overallFill.style.width = pct + '%';
        overallPct.textContent  = pct + '%';
    }

    const failed = pending.filter(i => i.status === 'error').length;
    overallLabel.textContent = failed === 0 ? '✓ All files uploaded!' : (pending.length - failed) + ' uploaded, ' + failed + ' failed';

    if (!failed) {
        const appNums = rows.map(r => r.appNum).join(', ');
        const label   = rows.length > 1 ? rows.length + ' applications' : 'Application No. ' + appNums;
        successBox.textContent = '✓ ' + pending.length + ' file' + (pending.length > 1 ? 's' : '') + ' uploaded for ' + label;
        successBox.style.display = 'block';
        setTimeout(() => location.reload(), 2000);
    } else {
        errorBox.textContent = failed + ' file(s) failed. You can retry.';
        errorBox.style.display = 'block';
        uploadBtn.disabled = false;
    }
}

function uploadOne(item, materialIds, doneCount, total) {
    return new Promise(resolve => {
        const fd = new FormData();
        fd.append('csrf_token',   CSRF);
        fd.append('material_ids', JSON.stringify(materialIds));
        fd.append('video',        item.file);

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/mtrcb_support/uploader/upload_file.php', true);

        xhr.upload.addEventListener('progress', ev => {
            if (!ev.lengthComputable) return;
            const filePct    = Math.round(ev.loaded / ev.total * 100);
            const overallVal = Math.round((doneCount + ev.loaded / ev.total) / total * 100);
            setStatus(item.id, 'uploading', filePct);
            overallFill.style.width = overallVal + '%';
            overallPct.textContent  = overallVal + '%';
        });

        xhr.addEventListener('load', () => {
            try { resolve(JSON.parse(xhr.responseText).ok === true); } catch { resolve(false); }
        });
        xhr.addEventListener('error', () => resolve(false));
        xhr.send(fd);
    });
}

function showError(msg) {
    errorBox.textContent = msg;
    errorBox.style.display = 'block';
    overallWrap.style.display = 'none';
    uploadBtn.disabled = false;
}

function fmtBytes(b) {
    if (b >= 1073741824) return (b/1073741824).toFixed(2)+' GB';
    if (b >= 1048576)    return (b/1048576).toFixed(2)+' MB';
    if (b >= 1024)       return (b/1024).toFixed(2)+' KB';
    return b+' B';
}
function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Init
renderRows();
</script>
</body>
</html>
