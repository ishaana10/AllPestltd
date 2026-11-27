<?php
declare(strict_types=1);
/** Admin API: certificates. GET ?treatment_id= status per container ; POST action=verify (container_id) | issue (treatment_id) | resend_pending. */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApAccess.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApUploads', 'ApRequests', 'ApTreatments', 'ApCertificates'] as $c) { require_once dirname(__DIR__, 2) . "/core/allpest/$c.php"; }

header('Content-Type: application/json');
$db = NuDatabase::getInstance();
ApSchema::ensure($db);
ApDb::set($db);
ApAccess::guard('admin.certificates');
$adminVerify = ApSettings::get('certificate_admin_verify', '0') === '1';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['pdf'])) {
        $cid = (string)($_GET['container_id'] ?? '');
        $c = $db->fetchOne('SELECT number, content_sha256 FROM ap_certificates WHERE container_id = ?', [$cid]);
        if (!$c) { http_response_code(404); header('Content-Type: application/json'); echo json_encode(['error' => 'No certificate has been issued for this container.']); exit; }
        if (ApCertificates::recomputeHash($cid) !== (string)$c['content_sha256']) {
            http_response_code(409); header('Content-Type: application/json'); echo json_encode(['error' => 'Certificate ' . $c['number'] . ' no longer matches the content issued.']); exit;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="Certificate-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$c['number']) . '.pdf"');
        echo ApCertificateRenderer::render(ApCertificates::data($cid));
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $rows = $db->fetchAll('SELECT tc.id, tc.result, tc.tlv_ppm, tc.verified_at, c.container_no, ce.number, ce.emailed_at, ce.email_pending FROM ap_treatment_containers tc
            JOIN ap_consignments c ON c.id = tc.consignment_id LEFT JOIN ap_certificates ce ON ce.container_id = tc.id WHERE tc.treatment_id = ? ORDER BY c.container_no', [(string)($_GET['treatment_id'] ?? '')]);
        foreach ($rows as &$r) { $r['release'] = ApCertificates::releasable((string)$r['id'], $adminVerify); }
        echo json_encode($rows);
        exit;
    }
    switch ((string)($_POST['action'] ?? '')) {
        case 'verify':
            ApCertificates::verify((string)($_POST['container_id'] ?? ''), (string)($_SESSION['nu_user_id'] ?? ''));
            echo json_encode(['ok' => true]);
            break;
        case 'issue':
            echo json_encode(ApCertificates::issueEligible((string)($_POST['treatment_id'] ?? ''), new DateTimeImmutable('now', new DateTimeZone('UTC'))));
            break;
        case 'resend_pending':
            echo json_encode(['sent' => ApCertificates::sendPending()]);
            break;
        default: http_response_code(400); echo json_encode(['error' => 'Unknown action']);
    }
} catch (InvalidArgumentException $e) {
    http_response_code(422); echo json_encode(['error' => $e->getMessage()]);
}
