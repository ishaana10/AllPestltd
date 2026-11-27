<?php
declare(strict_types=1);
/**
 * Public (no sign-in) endpoint behind public_allpest_request.php. POST multipart only; INSERT-only; never returns stored data.
 * Fields: customer_*, site_address, requested_date, client_token, website (honeypot), consignments[i][...], bol_i (file per container).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApUploads', 'ApRequests'] as $c) { require_once dirname(__DIR__, 2) . "/core/allpest/$c.php"; }
if (is_file(dirname(__DIR__, 2) . '/core/EmailService.php')) { require_once dirname(__DIR__, 2) . '/core/EmailService.php'; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'POST only']); exit; }

try {
    $db = NuDatabase::getInstance();
    ApSchema::ensure($db);
    ApDb::set($db);
    ApUploads::configure($db, null);
    $input = $_POST;
    $files = [];
    foreach (array_keys(is_array($input['consignments'] ?? null) ? $input['consignments'] : []) as $i) {
        if (isset($_FILES["bol_$i"]) && is_uploaded_file((string)($_FILES["bol_$i"]['tmp_name'] ?? ''))) { $files[(int)$i] = $_FILES["bol_$i"]; }
    }
    $r = ApRequests::submitPublic($input, $files, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    echo json_encode(['success' => true, 'reference' => $r['reference']]); // reference only: the visitor never reads stored data
} catch (ApIntakeRejected $e) {
    http_response_code(422); echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[allpest request_public] ' . $e->getMessage());
    http_response_code(500); echo json_encode(['success' => false, 'message' => 'Sorry, something went wrong. Please try again or call us.']);
}
