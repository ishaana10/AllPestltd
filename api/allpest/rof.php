<?php
declare(strict_types=1);
/** Record of fumigation. GET ?id= returns the whole record in paper order (sections A to E). POST action = details | temps. */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApAccess.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApUploads', 'ApRequests', 'ApNum', 'ApTreatments', 'ApRof'] as $c) { require_once dirname(__DIR__, 2) . "/core/allpest/$c.php"; }

header('Content-Type: application/json');   // the PDF branch below replaces this
$db = NuDatabase::getInstance();
ApSchema::ensure($db);
ApDb::set($db);
$uid = (string)($_SESSION['nu_user_id'] ?? '');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['options'])) {
        ApAccess::guard('admin.requests');
        echo json_encode([
            'schedules' => $db->fetchAll('SELECT id, name, treatment_type FROM ap_schedules WHERE active = 1 ORDER BY name'),
            'technicians' => $db->fetchAll("SELECT id, full_name FROM ap_technicians WHERE status = 'active' ORDER BY full_name"),
            'officers' => $db->fetchAll("SELECT id, full_name FROM ap_officers WHERE status = 'active' ORDER BY full_name"),
        ]);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = (string)($_GET['id'] ?? '');
        ApAccess::guard('treatment.view', $id);
        if (!empty($_GET['pdf'])) {
            require_once dirname(__DIR__, 2) . '/core/allpest/ApRofRenderer.php';
            $pdf = ApRofRenderer::render(ApRof::pdfData($id));
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="record-of-fumigation.pdf"');
            header('X-Content-Type-Options: nosniff');
            echo $pdf;
            exit;
        }
        echo json_encode(ApRof::document($id));
        exit;
    }
    if ((string)($_POST['action'] ?? '') === 'create') {
        // Staff start a record directly: a confirmed request with its containers and a planned treatment.
        ApAccess::guard('admin.requests');
        $containers = array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', (string)($_POST['containers'] ?? '')))));
        if (!$containers) { throw new InvalidArgumentException('Enter at least one container number.'); }
        $email = trim((string)($_POST['customer_email'] ?? ''));
        if ($email === '') { ApRequests::setMailer(function (): void {}); }   // no address to write to: skip the acknowledgement mail
        try {
        $req = ApRequests::submitPublic(['customer_name' => (string)($_POST['customer_name'] ?? ''), 'customer_email' => $email !== '' ? $email : 'none@invalid.example', 'customer_address' => (string)($_POST['customer_address'] ?? ''),
            'site_address' => (string)($_POST['site_address'] ?? ''), 'client_token' => bin2hex(random_bytes(16)), 'consignments' => array_map(fn ($c) => ['container_no' => $c], $containers)], [], 'staff:' . substr($uid, 0, 30));
        } finally { if ($email === '') { ApRequests::setMailer(null); } }
        ApRequests::confirm($req['request_id'], [], $uid);
        $ids = array_column($db->fetchAll('SELECT id FROM ap_consignments WHERE request_id = ?', [$req['request_id']]), 'id');
        try { $start = (new DateTimeImmutable((string)($_POST['scheduled_start'] ?? '')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); } catch (Throwable $e) { throw new InvalidArgumentException('Enter the planned start date and time.'); }
        $tids = ApRequests::createTreatments($req['request_id'], $ids, ['scheduled_start' => $start, 'technician_id' => (string)($_POST['technician_id'] ?? ''), 'officer_id' => (string)($_POST['officer_id'] ?? ''),
            'schedule_id' => (string)($_POST['schedule_id'] ?? ''), 'rof_type' => (string)($_POST['rof_type'] ?? 'general'), 'enclosure_type' => (string)($_POST['enclosure_type'] ?? ''), 'fumigation_type' => (string)($_POST['fumigation_type'] ?? 'ambient')]);
        echo json_encode(['treatment_id' => $tids[0], 'treatment_ids' => $tids, 'reference' => $req['reference']]);
        exit;
    }
    $tid = (string)($_POST['treatment_id'] ?? '');
    ApAccess::guard('treatment.record', $tid);
    switch ((string)($_POST['action'] ?? '')) {
        case 'details':
            $in = json_decode((string)($_POST['fields'] ?? ''), true);
            echo json_encode(['saved' => ApRof::saveDetails($tid, is_array($in) ? $in : [], $uid)]);
            break;
        case 'temps':
            $values = json_decode((string)($_POST['values'] ?? ''), true);
            try { $at = (new DateTimeImmutable((string)($_POST['at'] ?? '')))->setTimezone(new DateTimeZone('UTC')); } catch (Throwable $e) { throw new InvalidArgumentException('A valid date and time is required.'); }
            echo json_encode(['id' => ApRof::temperatures($tid, $at, is_array($values) ? $values : [], (string)($_POST['initials'] ?? ''), $uid)]);
            break;
        default:
            http_response_code(400); echo json_encode(['error' => 'Unknown action']);
    }
} catch (ApLockedException $e) {
    http_response_code(423); echo json_encode(['error' => $e->getMessage()]);
} catch (InvalidArgumentException | ApTreatmentError | ApRequestStateError | ApIntakeRejected $e) {
    http_response_code(422); echo json_encode(['error' => $e->getMessage()]);
}
