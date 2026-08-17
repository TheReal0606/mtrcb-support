<?php
require_once __DIR__ . '/includes/ip_guard.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json');

startSecureSession();
if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

$user = currentUser();
$file = $_FILES['video'] ?? null;

if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
    echo json_encode(['ok' => false, 'error' => 'No file selected.']);
    exit;
}

if ($file['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'No temporary directory available.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension.',
    ];
    echo json_encode(['ok' => false, 'error' => $uploadErrors[$file['error']] ?? 'Upload error ' . $file['error']]);
    exit;
}

// Size check
if ($file['size'] > MAX_FILE_SIZE) {
    echo json_encode(['ok' => false, 'error' => 'File exceeds 2 GB limit.']);
    exit;
}

// Extension check
$origName = $file['name'];
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
    echo json_encode(['ok' => false, 'error' => 'File type not allowed. Upload video files only.']);
    exit;
}

// MIME check using finfo
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']);
if (!in_array($mime, ALLOWED_MIMES, true)) {
    // Secondary check: many video containers report octet-stream — allow if extension is valid
    if ($mime !== 'application/octet-stream') {
        echo json_encode(['ok' => false, 'error' => 'File content does not appear to be a video.']);
        exit;
    }
}

// Generate safe filename
$safeFilename = bin2hex(random_bytes(16)) . '.' . $ext;
$destPath     = UPLOAD_DIR . $safeFilename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    echo json_encode(['ok' => false, 'error' => 'Failed to save file. Check server permissions.']);
    exit;
}

// Record in DB
$db = getDB();
if ($db === null) {
    unlink($destPath);
    echo json_encode(['ok' => false, 'error' => 'Database unavailable.']);
    exit;
}

$desc = trim($_POST['description'] ?? '');
$stmt = $db->prepare('INSERT INTO uploads (user_id, filename, original_name, file_size, mime_type, description) VALUES (?, ?, ?, ?, ?, ?)');
$stmt->execute([$user['id'], $safeFilename, $origName, $file['size'], $mime, $desc ?: null]);

echo json_encode(['ok' => true]);
