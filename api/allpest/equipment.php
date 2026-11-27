<?php
declare(strict_types=1);
/** Admin API: equipment inventory. GET ?list=all|selectable ; POST action=create|log (kind audit|discard|replace|repair). */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApAccess.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApEquipment'] as $c) { require_once dirname(__DIR__, 2) . "/core/allpest/$c.php"; }

header('Content-Type: application/json');
$db = NuDatabase::getInstance();
ApSchema::ensure($db);
ApDb::set($db);
$isAdmin = ApAccess::can((string)($_SESSION['nu_user_id'] ?? ''), 'admin.equipment');
if (!$isAdmin) { ApAccess::guard('treatment.record'); }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    echo json_encode(($_GET['list'] ?? 'selectable') === 'all' && $isAdmin ? $db->fetchAll('SELECT * FROM ap_equipment ORDER BY kind, name') : ApEquipment::selectable($now));
    exit;
}
if (!$isAdmin) { http_response_code(403); echo json_encode(['error' => 'Access denied']); exit; }
$in = $_POST;
try {
    switch ((string)($in['action'] ?? '')) {
        case 'create':
            $id = ApId::uuid();
            $db->insert('ap_equipment', [
                'id' => $id, 'kind' => (string)($in['kind'] ?? ''), 'name' => (string)($in['name'] ?? ''), 'serial_no' => (string)($in['serial_no'] ?? ''),
                'next_audit_due' => ($in['next_audit_due'] ?? '') ?: null, 'expiry_date' => ($in['expiry_date'] ?? '') ?: null, 'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            echo json_encode(['id' => $id]);
            break;
        case 'log':
            ApEquipment::logEvent((string)($in['equipment_id'] ?? ''), (string)($in['kind'] ?? ''), (string)($in['note'] ?? ''), (string)($_SESSION['nu_user_id'] ?? ''), ($in['next_audit_due'] ?? '') ?: null);
            echo json_encode(['ok' => true]);
            break;
        default: http_response_code(400); echo json_encode(['error' => 'Unknown action']);
    }
} catch (InvalidArgumentException $e) {
    http_response_code(422); echo json_encode(['error' => $e->getMessage()]);
}
