<?php
declare(strict_types=1);
/** Admin API: invoice links. GET ?request_id= | list ; POST action=release|retry (request_id). */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApAccess.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApErpxClient', 'ApInvoices'] as $c) { require_once dirname(__DIR__, 2) . "/core/allpest/$c.php"; }

header('Content-Type: application/json');
$db = NuDatabase::getInstance();
ApSchema::ensure($db);
ApDb::set($db);
ApAccess::guard('admin.invoices');
$user = (string)($_SESSION['nu_user_id'] ?? '');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $rid = (string)($_GET['request_id'] ?? '');
        echo json_encode($rid !== '' ? $db->fetchOne('SELECT * FROM ap_invoice_links WHERE request_id = ?', [$rid])
            : $db->fetchAll('SELECT l.*, r.reference FROM ap_invoice_links l JOIN ap_requests r ON r.id = l.request_id ORDER BY l.created_at DESC LIMIT 200'));
        exit;
    }
    $in = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
    $rid = (string)($in['request_id'] ?? '');
    if ($rid === '') { http_response_code(400); echo json_encode(['error' => 'request_id is required']); exit; }
    switch ((string)($in['action'] ?? '')) {
        case 'release': ApInvoices::release($rid, $user); break;
        case 'retry': ApInvoices::raiseForRequest($rid); break;
        default: http_response_code(400); echo json_encode(['error' => 'Unknown action']); exit;
    }
    echo json_encode($db->fetchOne('SELECT * FROM ap_invoice_links WHERE request_id = ?', [$rid]));
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage()]);
}
