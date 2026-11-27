<?php
declare(strict_types=1);
/**
 * Technician API for the ROF. POST action = precheck | core_temp | dose | readings | topup | ventilation | tlv | close ; GET ?id= returns the treatment.
 * Times arrive as ISO-8601 (the tablet sends its local time with offset) and are stored in UTC.
 */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApAccess.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApUploads', 'ApRequests', 'ApTreatments', 'ApStock'] as $c) { require_once dirname(__DIR__, 2) . "/core/allpest/$c.php"; }

/** Fetch the forecast on the server (never trusted from the tablet) for the treatment's site and exposure window. */
function apFetchForecast(object $db, string $tid, DateTimeZone $utc): array
{
    require_once dirname(__DIR__, 2) . '/core/allpest/ApForecastClient.php';
    $t = $db->fetchOne('SELECT t.site_lat, t.site_lng, t.scheduled_start, s.exposure_hours FROM ap_treatments t JOIN ap_schedules s ON s.id = t.schedule_id WHERE t.id = ?', [$tid]);
    if (!$t || $t['site_lat'] === null || $t['site_lng'] === null || !$t['scheduled_start']) { throw new InvalidArgumentException('The site location and start time are needed for the automatic forecast. Enter a manual forecast with a note instead.'); }
    $from = new DateTimeImmutable((string)$t['scheduled_start'], $utc);
    $to = $from->modify('+' . (int)ceil((float)$t['exposure_hours']) . ' hours');
    $http = static function (string $url): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6]);
        $out = curl_exec($ch);
        if ($out === false) { $e = curl_error($ch); curl_close($ch); throw new RuntimeException($e); }
        curl_close($ch);
        return (string)$out;
    };
    try {
        return (new ApForecastClient($http))->fetch((float)$t['site_lat'], (float)$t['site_lng'], $from, $to);
    } catch (ApForecastUnavailable $e) {
        throw new InvalidArgumentException($e->getMessage() . ' Enter a manual forecast with a note instead.');
    }
}

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
        if (!empty($_GET['lots'])) {
            $lots = $db->fetchAll('SELECT id, lot_ref FROM ap_stock_lots ORDER BY lot_ref');
            foreach ($lots as &$l) { $l['balance'] = ApStock::balance((string)$l['id']); }
            echo json_encode($lots);
            exit;
        }
        if (!empty($_GET['mine'])) {
            $uid = (string)($_SESSION['nu_user_id'] ?? '');
            $admin = ApAccess::can($uid, 'admin.treatments');
            $rows = $db->fetchAll("SELECT t.id, t.status, t.rof_type, t.scheduled_start, r.reference, r.customer_name, r.site_address FROM ap_treatments t JOIN ap_requests r ON r.id = t.request_id "
                . ($admin ? '' : 'WHERE t.technician_id IN (SELECT id FROM ap_technicians WHERE user_id = ?) ') . 'ORDER BY t.scheduled_start DESC LIMIT 100', $admin ? [] : [$uid]);
            echo json_encode($rows);
            exit;
        }
        ApAccess::guard('treatment.view', (string)($_GET['id'] ?? ''));
        $t = $db->fetchOne('SELECT * FROM ap_treatments WHERE id = ?', [(string)($_GET['id'] ?? '')]);
        if (!$t) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }
        if (!empty($_GET['live'])) { $t['live'] = ApTreatments::liveView($t['id'], new DateTimeImmutable('now', $utc)); }
        $t['containers'] = $db->fetchAll('SELECT tc.*, c.container_no, c.seal_no FROM ap_treatment_containers tc JOIN ap_consignments c ON c.id = tc.consignment_id WHERE tc.treatment_id = ? ORDER BY c.container_no', [$t['id']]);
        echo json_encode($t);
        exit;
    }
    $tid = (string)($_POST['treatment_id'] ?? '');
    ApAccess::guard('treatment.record', $tid);
    switch ((string)($_POST['action'] ?? '')) {
        case 'precheck':
            $in = json_decode((string)($_POST['input'] ?? ''), true);
            if (is_array($in) && ($in['forecast'] ?? null) === 'auto') {
                $in['forecast'] = apFetchForecast($db, $tid, $utc);
            }
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
