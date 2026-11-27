<?php
declare(strict_types=1);
/**
 * Technician API for the ROF. POST action = precheck | core_temp | dose | readings | topup | ventilation | tlv | close ; GET ?id= returns the treatment.
 * Times arrive as ISO-8601 (the tablet sends its local time with offset) and are stored in UTC.
 */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApAccess.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApUploads', 'ApRequests', 'ApTreatments'] as $c) { require_once dirname(__DIR__, 2) . "/core/allpest/$c.php"; }

header('Content-Type: application/json');
$db = NuDatabase::getInstance();
ApSchema::ensure($db);
ApDb::set($db);
ApAccess::guard('treatment.record');
$utc = new DateTimeZone('UTC');
$when = function (string $k) use ($utc): DateTimeImmutable {
    $v = (string)($_POST[$k] ?? '');
    try { return (new DateTimeImmutable($v))->setTimezone($utc); } catch (Throwable $e) { throw new InvalidArgumentException("A valid date and time is required ($k)."); }
};

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        ApAccess::guard('treatment.view', (string)($_GET['id'] ?? ''));
        $t = $db->fetchOne('SELECT * FROM ap_treatments WHERE id = ?', [(string)($_GET['id'] ?? '')]);
        if (!$t) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }
        $t['containers'] = $db->fetchAll('SELECT tc.*, c.container_no, c.seal_no FROM ap_treatment_containers tc JOIN ap_consignments c ON c.id = tc.consignment_id WHERE tc.treatment_id = ? ORDER BY c.container_no', [$t['id']]);
        echo json_encode($t);
        exit;
    }
    $tid = (string)($_POST['treatment_id'] ?? '');
    ApAccess::guard('treatment.record', $tid);
    switch ((string)($_POST['action'] ?? '')) {
        case 'precheck':
            $in = json_decode((string)($_POST['input'] ?? ''), true);
            echo json_encode(ApTreatments::preCheck($tid, is_array($in) ? $in : [], new DateTimeImmutable('now', $utc)));
            break;
        case 'core_temp':
            ApTreatments::recordCoreTemp($tid, (string)($_POST['container_id'] ?? ''), (float)($_POST['temp_c'] ?? 0));
            echo json_encode(['ok' => true]);
            break;
        case 'dose':
            ApTreatments::recordDosing($tid, $when('injected_at'), (float)($_POST['grams'] ?? 0), (string)($_POST['lot_id'] ?? ''));
            echo json_encode(['ok' => true]);
            break;
        case 'readings':
            $values = json_decode((string)($_POST['values'] ?? ''), true);
            echo json_encode(ApTreatments::recordReadingSet($tid, (string)($_POST['client_uuid'] ?? ''), (string)($_POST['stage'] ?? ''), $when('at'), is_array($values) ? $values : [], (array)($_POST['file_ids'] ?? []), (string)($_POST['initials'] ?? '')));
            break;
        case 'topup':
            ApTreatments::recordTopUp($tid, $when('at'), (float)($_POST['grams'] ?? 0), (string)($_POST['lot_id'] ?? ''));
            echo json_encode(['ok' => true]);
            break;
        case 'ventilation':
            ApTreatments::recordVentilation($tid, $when('at'));
            echo json_encode(['ok' => true]);
            break;
        case 'tlv':
            ApTreatments::recordTlv($tid, (string)($_POST['container_id'] ?? ''), (float)($_POST['ppm'] ?? -1), $when('at'));
            echo json_encode(['ok' => true]);
            break;
        case 'close':
            echo json_encode(ApTreatments::close($tid));
            break;
        default:
            http_response_code(400); echo json_encode(['error' => 'Unknown action']);
    }
} catch (ApLockedException $e) {
    http_response_code(423); echo json_encode(['error' => $e->getMessage()]);
} catch (InvalidArgumentException | ApTreatmentError | ApRequestStateError $e) {
    http_response_code(422); echo json_encode(['error' => $e->getMessage()]);
}
