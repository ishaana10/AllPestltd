<?php
declare(strict_types=1);
/** Admin API: methyl bromide stock. GET ?report=usage&from=&to= | low&threshold= | default lots+balances ; POST action=create_lot|receive|adjust. */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApStock'] as $c) { require_once dirname(__DIR__, 2) . "/core/allpest/$c.php"; }

header('Content-Type: application/json');
if (!$auth->hasPermission('allpest.admin')) { http_response_code(403); echo json_encode(['error' => 'Access denied']); exit; }
$db = NuDatabase::getInstance();
ApSchema::ensure($db);
ApDb::set($db);
$utc = new DateTimeZone('UTC');
$user = (string)($_SESSION['nu_user_id'] ?? '');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        switch ((string)($_GET['report'] ?? '')) {
            case 'usage':
                echo json_encode(ApStock::usageReport(new DateTimeImmutable((string)($_GET['from'] ?? 'first day of this month 00:00:00'), $utc), new DateTimeImmutable((string)($_GET['to'] ?? 'now'), $utc)));
                break;
            case 'low':
                echo json_encode(ApStock::lowStock((float)($_GET['threshold'] ?? 7000)));
                break;
            default:
                $lots = $db->fetchAll('SELECT * FROM ap_stock_lots ORDER BY lot_ref');
                foreach ($lots as &$l) { $l['balance'] = ApStock::balance((string)$l['id']); }
                echo json_encode($lots);
        }
        exit;
    }
    switch ((string)($_POST['action'] ?? '')) {
        case 'create_lot': echo json_encode(['id' => ApStock::createLot((string)($_POST['lot_ref'] ?? ''), (string)($_POST['description'] ?? ''), (float)($_POST['mb_fraction'] ?? 1))]); break;
        case 'receive': ApStock::receive((string)($_POST['lot_id'] ?? ''), (float)($_POST['grams'] ?? 0), $user); echo json_encode(['ok' => true]); break;
        case 'adjust': ApStock::adjust((string)($_POST['lot_id'] ?? ''), (float)($_POST['grams'] ?? 0), (string)($_POST['reason'] ?? ''), $user); echo json_encode(['ok' => true]); break;
        default: http_response_code(400); echo json_encode(['error' => 'Unknown action']);
    }
} catch (InvalidArgumentException $e) {
    http_response_code(422); echo json_encode(['error' => $e->getMessage()]);
}
