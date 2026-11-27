<?php
declare(strict_types=1);
/**
 * Old sidebar entry from the first dashboard build. The dashboard is now the studio's own Dashboard (widgets) and Reports; an admin who
 * opens this stale entry refreshes the All Pest menu and widgets, then lands on the studio Dashboard.
 */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApAccess.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApForms'] as $c) { require_once dirname(__DIR__, 2) . "/core/allpest/$c.php"; }

$db = NuDatabase::getInstance();
ApSchema::ensure($db);
ApDb::set($db);
if (ApAccess::can((string)($_SESSION['nu_user_id'] ?? ''), 'admin.settings')) {
    try { ApForms::provision($db, (int)ProjectContext::getId(), null); } catch (Throwable $e) { error_log('[allpest dashboard shim] ' . $e->getMessage()); }
}
?>
<div style="padding:32px;text-align:center">
  <p>The dashboard has moved. Opening the Dashboard… (reload the page once to refresh the menu).</p>
</div>
<script>if (window.NuApp && typeof NuApp.loadModule === 'function') { NuApp.loadModule('dashboard'); }</script>
