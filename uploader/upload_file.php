<?php
/**
 * AJAX: Upload one video file, link it to one or more material IDs.
 * Physical file saved on VPS, then forwarded to desktop receiver.
 * Returns: {ok: true} or {ok: false, error: "..."}
 */
require_once dirname(__DIR__) . '/includes/ip_guard.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/db.php';

header('Content-Type: application/json');

startSecureSession();
if (!isLoggedIn()) { echo json_encode(['ok'=>false,'error'=>'Not authenticated.']); exit; }
if (!in_array($_SESSION['role']??'', ['uploader','admin'], true)) { echo json_encode(['ok'=>false,'error'=>'Forbidden.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'error'=>'Method not allowed.']); exit; }
if (!verifyCsrf($_POST['csrf_token'] ?? '')) { echo json_encode(['ok'=>false,'error'=>'Invalid CSRF token.']); exit; }

$idsJson     = $_POST['material_ids'] ?? '';
$materialIds = json_decode($idsJson, true);
if (!is_array($materialIds) || empty($materialIds)) {
    echo json_encode(['ok'=>false,'error'=>'No material IDs provided.']); exit;
}

$db = getDB();
if (!$db) { echo json_encode(['ok'=>false,'error'=>'Database unavailable.']); exit; }

$role = $_SESSION['role'] ?? '';
foreach ($materialIds as $mid) {
    $mid = (int)$mid;
    if ($mid <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid material ID.']); exit; }
    $chk = $db->prepare('SELECT id FROM materials WHERE id = ? AND (submitted_by = ? OR ? = "admin")');
    $chk->execute([$mid, $_SESSION['user_id'], $role]);
    if (!$chk->fetch()) { echo json_encode(['ok'=>false,'error'=>'Material ID '.$mid.' not found or access denied.']); exit; }
}

// File validation
$file = $_FILES['video'] ?? null;
if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
    echo json_encode(['ok'=>false,'error'=>'No file received.']); exit;
}
$uploadErrors = [
    UPLOAD_ERR_INI_SIZE   => 'File exceeds server limit.',
    UPLOAD_ERR_FORM_SIZE  => 'File exceeds form limit.',
    UPLOAD_ERR_PARTIAL    => 'Partial upload.',
    UPLOAD_ERR_NO_TMP_DIR => 'No tmp directory.',
    UPLOAD_ERR_CANT_WRITE => 'Cannot write to disk.',
];
if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok'=>false,'error'=>$uploadErrors[$file['error']] ?? 'Upload error '.$file['error']]); exit;
}
if ($file['size'] > MAX_FILE_SIZE) {
    echo json_encode(['ok'=>false,'error'=>'File exceeds 500 MB limit.']); exit;
}

$origName = $file['name'];
$ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
    echo json_encode(['ok'=>false,'error'=>'File type not allowed.']); exit;
}
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']);
if (!in_array($mime, ALLOWED_MIMES, true)) {
    echo json_encode(['ok'=>false,'error'=>'File does not appear to be a video.']); exit;
}

// Ensure VPS upload dir exists
if (!is_dir(UPLOAD_DIR)) {
    @mkdir(UPLOAD_DIR, 0750, true);
}

// Save physical file on VPS
$safeFilename = bin2hex(random_bytes(16)) . '.' . $ext;
$destPath     = UPLOAD_DIR . $safeFilename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    echo json_encode(['ok'=>false,'error'=>'Failed to save file on server. Check folder permissions.']); exit;
}

// Forward to desktop receiver
$forwardError = null;
if (defined('FORWARD_TO_DESKTOP') && FORWARD_TO_DESKTOP) {
    $forwardError = forwardToDesktop($destPath, $safeFilename, $origName, $mime);
}

// Record in DB (file stored even if forward failed — recoverable)
$insertStmt = $db->prepare(
    'INSERT INTO material_files (material_id, filename, original_name, file_size, mime_type) VALUES (?, ?, ?, ?, ?)'
);
$updateStmt = $db->prepare('UPDATE materials SET status = "uploaded" WHERE id = ?');
foreach ($materialIds as $mid) {
    $mid = (int)$mid;
    $insertStmt->execute([$mid, $safeFilename, $origName, $file['size'], $mime]);
    $updateStmt->execute([$mid]);
}

if ($forwardError) {
    // File is on VPS but didn't reach desktop — surface as warning, not failure
    echo json_encode(['ok'=>true,'warning'=>'Saved on server but desktop forward failed: '.$forwardError]);
} else {
    echo json_encode(['ok'=>true]);
}

// ────────────────────────────────────────────────────────────────
function forwardToDesktop(string $filePath, string $filename, string $origName, string $mime): ?string {
    if (!function_exists('curl_init')) {
        return 'cURL not available on server.';
    }

    $ch = curl_init(DESKTOP_RECEIVER_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'secret'        => RECEIVER_SECRET,
            'safe_filename' => $filename,
            'original_name' => $origName,
            'file'          => new CURLFile($filePath, $mime, $origName),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 7200,      // 2 hours max for large files
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err)        return 'cURL error: ' . $err;
    if ($code !== 200) return 'Receiver returned HTTP ' . $code;

    $json = json_decode($body, true);
    if (!($json['ok'] ?? false)) return 'Receiver error: ' . ($json['error'] ?? 'unknown');

    return null; // success
}
