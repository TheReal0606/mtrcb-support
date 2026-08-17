<?php
/**
 * AJAX: Create one or more material records.
 * Accepts JSON rows: [{application_number, title}, ...]
 * Returns: {ok: true, material_ids: [N, ...]}
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

$rowsJson = $_POST['rows'] ?? '';
$rows     = json_decode($rowsJson, true);

if (!is_array($rows) || empty($rows)) {
    echo json_encode(['ok'=>false,'error'=>'No application rows provided.']); exit;
}

$db = getDB();
if (!$db) { echo json_encode(['ok'=>false,'error'=>'Database unavailable.']); exit; }

$stmt = $db->prepare('INSERT INTO materials (application_number, title, submitted_by, status) VALUES (?, ?, ?, "pending")');
$ids  = [];

foreach ($rows as $row) {
    $appNum = trim($row['application_number'] ?? '');
    $title  = trim($row['title'] ?? '');
    if ($appNum === '' || strlen($appNum) > 100 || $title === '' || strlen($title) > 255) {
        echo json_encode(['ok'=>false,'error'=>'Invalid row: application_number or title missing.']); exit;
    }
    $stmt->execute([$appNum, $title, $_SESSION['user_id']]);
    $ids[] = (int)$db->lastInsertId();
}

echo json_encode(['ok'=>true, 'material_ids'=>$ids]);
