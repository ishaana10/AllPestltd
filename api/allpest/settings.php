<?php
declare(strict_types=1);
/** Admin API: install settings. GET returns them (the ERPX key is masked) plus due reminders; POST {key, value} sets one whitelisted key; POST action=install creates roles and the menu. */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApAccess.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApStock', 'ApReminders'] as $c) { require_once dirname(__DIR__, 2) . "/core/allpest/$c.php"; }

header('Content-Type: application/json');
$db = NuDatabase::getInstance();
ApSchema::ensure($db);
ApDb::set($db);
ApAccess::guard('admin.settings');

const AP_SETTING_KEYS = ['company_name', 'company_address', 'treatment_provider_id', 'staff_email', 'invoice.mode', 'erpx.base_url', 'erpx.api_key', 'erpx.product.general', 'erpx.product.perishable',
    'erpx.tax_rate', 'certificate_admin_verify', 'certificate_target', 'stock.low_threshold_g'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $out = [];
    foreach (AP_SETTING_KEYS as $k) { $out[$k] = (string)ApSettings::get($k, ''); }
    if ($out['erpx.api_key'] !== '') { $out['erpx.api_key'] = str_repeat('*', 8); }
    echo json_encode(['settings' => $out, 'reminders' => ApReminders::due(new DateTimeImmutable('now', new DateTimeZone('UTC')))]);
    exit;
}
if (($_POST['action'] ?? '') === 'install') {
    ApAccess::install($db, (int)ProjectContext::getId());
    echo json_encode(['ok' => true]);
    exit;
}
$k = (string)($_POST['key'] ?? '');
if (!in_array($k, AP_SETTING_KEYS, true)) { http_response_code(422); echo json_encode(['error' => 'Unknown setting']); exit; }
$v = trim((string)($_POST['value'] ?? ''));
if ($k === 'erpx.api_key' && $v === str_repeat('*', 8)) { echo json_encode(['ok' => true]); exit; }
if ($k === 'invoice.mode' && !in_array($v, ['auto', 'reviewed'], true)) { http_response_code(422); echo json_encode(['error' => 'Mode must be auto or reviewed']); exit; }
if ($k === 'staff_email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) { http_response_code(422); echo json_encode(['error' => 'Not a valid email address']); exit; }
if ($k === 'erpx.tax_rate' && !preg_match('/^\d{1,3}(\.\d{1,2})?$/', $v)) { http_response_code(422); echo json_encode(['error' => 'Tax rate is a percentage like 15 or 12.5']); exit; }
ApSettings::set($k, $v);
echo json_encode(['ok' => true]);
