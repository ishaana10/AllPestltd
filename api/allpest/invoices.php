<?php
declare(strict_types=1);
/**
 * Admin / finance API: local invoices. GET (no args) = list | ?id= | ?request_id= (invoice with its lines).
 * POST (JSON) action = update (id, discount?, notes?, due_date?, lines[]) | verify | reopen | post (id) | raise (request_id).
 */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApAccess.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApErpxClient', 'ApInvoices'] as $c) { require_once dirname(__DIR__, 2) . "/core/allpest/$c.php"; }

header('Content-Type: application/json');
$db = NuDatabase::getInstance();
ApSchema::ensure($db);
ApDb::set($db);
ApAccess::guard('admin.invoices');
$user = (string)($_SESSION['nu_user_id'] ?? '');

function ap_invoice_full($db, array $inv): array
{
    $inv['lines'] = $db->fetchAll('SELECT * FROM ap_invoice_lines WHERE invoice_id = ? ORDER BY sort_no', [$inv['id']]);
    $r = $db->fetchOne('SELECT reference FROM ap_requests WHERE id = ?', [$inv['request_id']]);
    $inv['reference'] = $r['reference'] ?? '';
    return $inv;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = (string)($_GET['id'] ?? '');
        $rid = (string)($_GET['request_id'] ?? '');
        if ($id !== '' || $rid !== '') {
            $inv = $id !== '' ? $db->fetchOne('SELECT * FROM ap_invoices WHERE id = ?', [$id]) : $db->fetchOne('SELECT * FROM ap_invoices WHERE request_id = ?', [$rid]);
            if (!$inv) { http_response_code(404); echo json_encode(['error' => 'No invoice yet. It is raised when every ROF of the request is closed.']); exit; }
            echo json_encode(ap_invoice_full($db, $inv));
            exit;
        }
        echo json_encode($db->fetchAll('SELECT i.id, i.invoice_no, i.status, i.invoice_date, i.customer_name, i.total, i.erpx_invoice_no, i.last_error, i.attempts, r.reference FROM ap_invoices i JOIN ap_requests r ON r.id = i.request_id ORDER BY i.created_at DESC LIMIT 200'));
        exit;
    }
    $in = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
    $id = (string)($in['id'] ?? '');
    $action = (string)($in['action'] ?? '');
    if ($action === 'raise') {
        ApInvoices::raiseForRequest((string)($in['request_id'] ?? ''));
        $inv = $db->fetchOne('SELECT * FROM ap_invoices WHERE request_id = ?', [(string)($in['request_id'] ?? '')]);
        echo json_encode($inv ? ap_invoice_full($db, $inv) : ['error' => 'Not ready: every ROF of the request must be closed.']);
        exit;
    }
    if ($id === '') { http_response_code(400); echo json_encode(['error' => 'id is required']); exit; }
    switch ($action) {
        case 'update': ApInvoices::update($id, $in); break;
        case 'verify': ApInvoices::verify($id, $user); break;
        case 'reopen': ApInvoices::reopen($id, $user); break;
        case 'post': ApInvoices::post($id, $user); break;
        default: http_response_code(400); echo json_encode(['error' => 'Unknown action']); exit;
    }
    echo json_encode(ap_invoice_full($db, $db->fetchOne('SELECT * FROM ap_invoices WHERE id = ?', [$id])));
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage()]);
}
