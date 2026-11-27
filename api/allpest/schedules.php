<?php
declare(strict_types=1);
/** Admin API: treatment schedules. GET lists; POST saves (admin only). */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApAccess.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSchedules'] as $c) { require_once dirname(__DIR__, 2) . "/core/allpest/$c.php"; }

header('Content-Type: application/json');
$db = NuDatabase::getInstance();
ApSchema::ensure($db);
ApDb::set($db);
$isAdmin = ApAccess::can((string)($_SESSION['nu_user_id'] ?? ''), 'admin.schedules');
if (!$isAdmin) { ApAccess::guard('treatment.record'); }

if ($_SERVER['REQUEST_METHOD'] === 'GET') { echo json_encode(ApSchedules::all()); exit; }
if (!$isAdmin) { http_response_code(403); echo json_encode(['error' => 'Access denied']); exit; }
try {
    echo json_encode(['id' => ApSchedules::save($_POST)]);
} catch (InvalidArgumentException $e) {
    http_response_code(422); echo json_encode(['error' => $e->getMessage()]);
}
