<?php
declare(strict_types=1);
/** Admin API: install settings. GET returns them (the ERPX key is masked) plus due reminders; POST {key, value} sets one whitelisted key; POST action=install creates roles and the menu. */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApAccess.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApForms.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApUploads', 'ApBranding', 'ApStock', 'ApReminders'] as $c) { require_once dirname(__DIR__, 2) . "/core/allpest/$c.php"; }

header('Content-Type: application/json');
$db = NuDatabase::getInstance();
ApSchema::ensure($db);
ApDb::set($db);
ApAccess::guard('admin.settings');

const AP_SETTING_KEYS = ['company_name', 'company_address', 'company_phone', 'company_email', 'company_website', 'treatment_provider_id', 'staff_email', 'invoice.mode', 'erpx.base_url', 'erpx.api_key', 'erpx.product.general', 'erpx.product.perishable',
    'erpx.tax_rate', 'certificate_admin_verify', 'certificate_target', 'certificate_additional_declarations', 'stock.low_threshold_g'];

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['logo'])) {
    $b = ApBranding::load();
    if (!$b['logo_path']) { http_response_code(404); header('Content-Type: application/json'); echo json_encode(['error' => 'No logo set']); exit; }
    header('Content-Type: image/jpeg'); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-cache');
    readfile($b['logo_path']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $out = [];
    foreach (AP_SETTING_KEYS as $k) { $out[$k] = (string)ApSettings::get($k, ''); }
    if ($out['erpx.api_key'] !== '') { $out['erpx.api_key'] = str_repeat('*', 8); }
    $out['has_logo'] = ApBranding::load()['logo_path'] ? '1' : '';
    echo json_encode(['settings' => $out, 'reminders' => ApReminders::due(new DateTimeImmutable('now', new DateTimeZone('UTC')))]);
    exit;
}
if (($_POST['action'] ?? '') === 'logo') {
    try {
        if (!isset($_FILES['file'])) { throw new ApUploadRejected('Choose a logo image first.'); }
        ApUploads::configure($db, null);
        $r = ApUploads::store($_FILES['file'], 'other', (string)($_SESSION['nu_user_id'] ?? 'unknown'), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255));
        ApBranding::setLogo($r['file_id']);
        echo json_encode(['ok' => true]);
    } catch (ApUploadRejected | InvalidArgumentException $e) { http_response_code(422); echo json_encode(['error' => $e->getMessage()]); }
    exit;
}
if (($_POST['action'] ?? '') === 'logo_clear') { ApBranding::clearLogo(); echo json_encode(['ok' => true]); exit; }
if (($_POST['action'] ?? '') === 'install') {
    ApAccess::install($db, (int)ProjectContext::getId());
    $made = ApForms::provision($db, (int)ProjectContext::getId(), (int)($_SESSION['nu_user_id'] ?? 0));
    $token = ApForms::provisionPublicShare($db, (int)ProjectContext::getId(), (string)($_SESSION['nu_user_id'] ?? ''), (string)ApSettings::get('staff_email', ''));
    echo json_encode(['ok' => true, 'forms_created' => $made, 'public_form' => $token ? 'public_form.php?t=' . $token : null]);
    exit;
}
$k = (string)($_POST['key'] ?? '');
if (!in_array($k, AP_SETTING_KEYS, true)) { http_response_code(422); echo json_encode(['error' => 'Unknown setting']); exit; }
$v = trim((string)($_POST['value'] ?? ''));
if ($k === 'erpx.api_key' && $v === str_repeat('*', 8)) { echo json_encode(['ok' => true]); exit; }
if ($k === 'invoice.mode' && !in_array($v, ['auto', 'reviewed'], true)) { http_response_code(422); echo json_encode(['error' => 'Mode must be auto or reviewed']); exit; }
if (in_array($k, ['staff_email', 'company_email'], true) && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) { http_response_code(422); echo json_encode(['error' => 'Not a valid email address']); exit; }
if ($k === 'erpx.tax_rate' && !preg_match('/^\d{1,3}(\.\d{1,2})?$/', $v)) { http_response_code(422); echo json_encode(['error' => 'Tax rate is a percentage like 15 or 12.5']); exit; }
ApSettings::set($k, $v);
echo json_encode(['ok' => true]);
