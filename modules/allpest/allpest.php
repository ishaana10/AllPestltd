<?php
declare(strict_types=1);
/**
 * Old sidebar entry. An admin who opens it sets All Pest up inside the studio (roles, forms, menu group, customer request link) and is asked
 * to reload, after which the menu shows the studio screens. Anyone else is pointed at the technician pages.
 */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApAccess.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApForms'] as $c) { require_once dirname(__DIR__, 2) . "/core/allpest/$c.php"; }

$db = NuDatabase::getInstance();
ApSchema::ensure($db);
ApDb::set($db);
$uid = (string)($_SESSION['nu_user_id'] ?? '');
$msg = '';
if (ApAccess::can($uid, 'admin.settings')) {
    try {
        $pid = (int)ProjectContext::getId();
        ApAccess::install($db, $pid);
        $made = ApForms::provision($db, $pid, ((int)$uid) ?: null);
        ApForms::provisionPublicShare($db, $pid, $uid, (string)ApSettings::get('staff_email', ''));
        $msg = 'All Pest is set up (' . count($made) . ' new forms). Reload the page to see the All Pest menu.';
    } catch (Throwable $e) {
        error_log('[allpest setup] ' . $e->getMessage());
        $msg = 'Setup failed: ' . $e->getMessage();
    }
}
?>
<div style="padding:32px;text-align:center">
<?php if ($msg !== ''): ?>
  <p><?php echo htmlspecialchars($msg); ?></p>
  <p><button class="nu-btn nu-btn-primary" onclick="location.reload()">Reload</button></p>
<?php else: ?>
  <p>Technician pages: <a href="modules/allpest/index.php">Open All Pest</a></p>
<?php endif; ?>
</div>
