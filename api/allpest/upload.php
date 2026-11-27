<?php
declare(strict_types=1);
/**
 * Authenticated upload for technicians (reading photos, signatures, technician certificates).
 * POST multipart: file, purpose. Returns JSON {file_id, bytes, sha256}. The public request form has its own,
 * token-protected path (public_allpest_request.php) and does not use this endpoint.
 */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApAccess.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApSchema.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApUploads.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST only']); exit; }
$__db = NuDatabase::getInstance();
ApSchema::ensure($__db);
ApDb::set($__db);
ApAccess::guard('treatment.record');

$purpose = (string)($_POST['purpose'] ?? '');
if (!in_array($purpose, ['reading_photo', 'signature', 'technician_cert', 'equipment', 'other'], true)) {
    http_response_code(422); echo json_encode(['error' => 'Unknown purpose']); exit;
}
if (!isset($_FILES['file']) || !is_uploaded_file((string)($_FILES['file']['tmp_name'] ?? ''))) {
    http_response_code(422); echo json_encode(['error' => 'No file received']); exit;
}
try {
    $db = NuDatabase::getInstance();
    ApSchema::ensure($db);
    ApUploads::configure($db, null);
    $r = ApUploads::store($_FILES['file'], $purpose, (string)($_SESSION['nu_user_id'] ?? 'unknown'), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255));
    echo json_encode($r);
} catch (ApUploadRejected $e) {
    http_response_code(422); echo json_encode(['error' => $e->getMessage()]);
}
