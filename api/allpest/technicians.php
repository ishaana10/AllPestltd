<?php
declare(strict_types=1);
/** Admin API: technicians and officers. GET ?list=technicians|officers|expiring ; POST action=create_technician|add_officer|attach_file. */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApAccess.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApTechnicians'] as $c) { require_once dirname(__DIR__, 2) . "/core/allpest/$c.php"; }

header('Content-Type: application/json');
$db = NuDatabase::getInstance();
ApSchema::ensure($db);
ApDb::set($db);
ApAccess::guard('admin.technicians');
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    switch ((string)($_GET['list'] ?? 'technicians')) {
        case 'officers': echo json_encode(ApTechnicians::officers()); break;
        case 'expiring': echo json_encode(ApTechnicians::expiringWithin(min(365, max(1, (int)($_GET['days'] ?? 30))), $now)); break;
        default: echo json_encode($db->fetchAll('SELECT id, full_name, registration_no, registration_expiry, status FROM ap_technicians ORDER BY full_name'));
    }
    exit;
}
$in = $_POST;
switch ((string)($in['action'] ?? '')) {
    case 'create_technician':
        if (trim((string)($in['full_name'] ?? '')) === '') { http_response_code(422); echo json_encode(['error' => 'Name required']); break; }
        echo json_encode(['id' => ApTechnicians::create($in)]);
        break;
    case 'add_officer':
        if (trim((string)($in['full_name'] ?? '')) === '') { http_response_code(422); echo json_encode(['error' => 'Name required']); break; }
        echo json_encode(['id' => ApTechnicians::addOfficer((string)$in['full_name'], (string)($in['designation'] ?? ''))]);
        break;
    case 'attach_file':
        ApTechnicians::attachFile((string)($in['technician_id'] ?? ''), (string)($in['file_id'] ?? ''), (string)($in['label'] ?? ''));
        echo json_encode(['ok' => true]);
        break;
    default: http_response_code(400); echo json_encode(['error' => 'Unknown action']);
}
