<?php
declare(strict_types=1);
/**
 * E-signature endpoint. POST action = fumigator_sign (password, mfa_code) | officer_sign (name, designation, png) | correct (changes JSON, reason) ; GET ?treatment_id= signature state.
 * The signing user is always the signed-in session user, never a value from the request.
 */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApAccess.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApUploads', 'ApRequests', 'ApTreatments', 'ApSigning'] as $c) { require_once dirname(__DIR__, 2) . "/core/allpest/$c.php"; }

header('Content-Type: application/json');
$db = NuDatabase::getInstance();
ApSchema::ensure($db);
ApDb::set($db);
ApAccess::guard('treatment.sign');
$userId = (string)($_SESSION['nu_user_id'] ?? '');
if ($userId === '' || $userId === 'guest_tester') { http_response_code(403); echo json_encode(['error' => 'Access denied']); exit; }

$verify = function (string $uid, string $password, ?string $mfa) use ($db, $auth, $userId): bool {
    if ($uid !== $userId) { return false; }
    $u = $db->fetchOne('SELECT usr_password, usr_2fa_enabled, usr_2fa_secret FROM nu_users WHERE usr_id = :i', [':i' => $uid]);
    if (!$u || !password_verify($password, (string)$u['usr_password'])) { return false; }
    if (!empty($u['usr_2fa_enabled']) && !empty($u['usr_2fa_secret'])) {
        $secret = (string)$u['usr_2fa_secret'];
        if (class_exists('NuEncryption') && NuEncryption::isEncrypted($secret)) { $secret = NuEncryption::decryptField('nu_users', 'usr_2fa_secret', $secret); }
        return $mfa !== null && $mfa !== '' && $auth->verifyTOTP($secret, $mfa);
    }
    return true;
};
$signing = new ApSigning($verify);
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$tid = (string)($_POST['treatment_id'] ?? $_GET['treatment_id'] ?? '');
ApAccess::guard(($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' ? 'treatment.view' : 'treatment.sign', $tid);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(['locked' => $signing->isLocked($tid), 'fully_signed' => $signing->isFullySigned($tid), 'signatures' => array_map(fn ($s) => ['role' => $s['role'], 'name' => $s['signer_name'], 'signed_at' => $s['signed_at']], array_values($signing->signatures($tid)))]);
        exit;
    }
    switch ((string)($_POST['action'] ?? '')) {
        case 'fumigator_sign':
            echo json_encode(['recorded' => $signing->fumigatorSign($tid, $userId, (string)($_POST['password'] ?? ''), ($_POST['mfa_code'] ?? '') !== '' ? (string)$_POST['mfa_code'] : null, $now)]);
            break;
        case 'officer_sign':
            echo json_encode(['recorded' => $signing->officerSign($tid, (string)($_POST['name'] ?? ''), (string)($_POST['designation'] ?? ''), (string)($_POST['png'] ?? ''), $userId, $now)]);
            break;
        case 'correct':
            ApAccess::guard('admin.correct');   // a technician must not rewrite their own signed record
            $changes = json_decode((string)($_POST['changes'] ?? ''), true);
            echo json_encode(['version' => $signing->correct($tid, is_array($changes) ? $changes : [], (string)($_POST['reason'] ?? ''), $userId)]);
            break;
        default: http_response_code(400); echo json_encode(['error' => 'Unknown action']);
    }
} catch (ApSigningRefused $e) {
    http_response_code(403); echo json_encode(['error' => $e->getMessage()]);
} catch (InvalidArgumentException | ApTreatmentError $e) {
    http_response_code(422); echo json_encode(['error' => $e->getMessage()]);
}
