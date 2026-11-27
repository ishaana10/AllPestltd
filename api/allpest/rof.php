<?php
declare(strict_types=1);
/** Record of fumigation. GET ?id= returns the whole record in paper order (sections A to E). POST action = details | temps. */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApAccess.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApUploads', 'ApRequests', 'ApNum', 'ApTreatments', 'ApRof'] as $c) { require_once dirname(__DIR__, 2) . "/core/allpest/$c.php"; }

header('Content-Type: application/json');
$db = NuDatabase::getInstance();
ApSchema::ensure($db);
ApDb::set($db);
$uid = (string)($_SESSION['nu_user_id'] ?? '');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = (string)($_GET['id'] ?? '');
        ApAccess::guard('treatment.view', $id);
        echo json_encode(ApRof::document($id));
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
} catch (InvalidArgumentException | ApTreatmentError $e) {
    http_response_code(422); echo json_encode(['error' => $e->getMessage()]);
}
