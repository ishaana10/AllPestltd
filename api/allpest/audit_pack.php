<?php
declare(strict_types=1);
/** Admin API: GET ?treatment_id= downloads the audit pack ZIP for one treatment. */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApAccess.php';
require_once dirname(__DIR__, 2) . '/core/Audit.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApUploads', 'ApAudit', 'ApAuditPack'] as $c) { require_once dirname(__DIR__, 2) . "/core/allpest/$c.php"; }

$db = NuDatabase::getInstance();
ApSchema::ensure($db);
ApDb::set($db);
ApAccess::guard('admin.audit_pack');
ApUploads::configure($db, null);
$tid = (string)($_GET['treatment_id'] ?? '');
if ($tid === '') { http_response_code(400); header('Content-Type: application/json'); echo json_encode(['error' => 'treatment_id is required']); exit; }
try {
    ApAudit::event('audit_pack.exported', $tid, []);
    ApAuditPack::stream($tid);
} catch (Throwable $e) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}
