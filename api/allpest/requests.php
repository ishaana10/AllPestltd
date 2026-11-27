<?php
declare(strict_types=1);
/** Admin API: request inbox. GET ?id= (one, with consignments and treatments) or list ; POST action=confirm|schedule. */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApAccess.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApUploads', 'ApRequests'] as $c) { require_once dirname(__DIR__, 2) . "/core/allpest/$c.php"; }

header('Content-Type: application/json');
$db = NuDatabase::getInstance();
ApSchema::ensure($db);
ApDb::set($db);
ApAccess::guard('admin.requests');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!empty($_GET['id'])) {
            $id = (string)$_GET['id'];
            $req = $db->fetchOne('SELECT * FROM ap_requests WHERE id = ?', [$id]);
            if (!$req) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }
            $req['consignments'] = $db->fetchAll('SELECT c.*, tc.treatment_id FROM ap_consignments c LEFT JOIN ap_treatment_containers tc ON tc.consignment_id = c.id WHERE c.request_id = ? ORDER BY c.container_no', [$id]);
            $req['treatments'] = $db->fetchAll('SELECT id, rof_type, status, technician_id, scheduled_start FROM ap_treatments WHERE request_id = ? ORDER BY scheduled_start', [$id]);
            echo json_encode($req);
        } else {
            $st = (string)($_GET['status'] ?? '');
            echo json_encode($st !== ''
                ? $db->fetchAll('SELECT id, reference, customer_name, status, requested_date, created_at FROM ap_requests WHERE status = ? ORDER BY created_at DESC LIMIT 200', [$st])
                : $db->fetchAll('SELECT id, reference, customer_name, status, requested_date, created_at FROM ap_requests ORDER BY created_at DESC LIMIT 200'));
        }
        exit;
    }
    $user = (string)($_SESSION['nu_user_id'] ?? '');
    switch ((string)($_POST['action'] ?? '')) {
        case 'confirm':
            ApRequests::confirm((string)($_POST['request_id'] ?? ''), $_POST, $user);
            echo json_encode(['ok' => true]);
            break;
        case 'schedule':
            echo json_encode(['treatment_id' => ApRequests::createTreatment((string)($_POST['request_id'] ?? ''), (array)($_POST['container_ids'] ?? []), $_POST)]);
            break;
        default: http_response_code(400); echo json_encode(['error' => 'Unknown action']);
    }
} catch (InvalidArgumentException | ApRequestStateError $e) {
    http_response_code(422); echo json_encode(['error' => $e->getMessage()]);
}
