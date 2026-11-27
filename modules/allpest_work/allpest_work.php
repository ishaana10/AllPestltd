<?php
declare(strict_types=1);
/**
 * Treatment workspace, loaded inline in the StudioX shell (sidebar: All Pest > Treatment Workspace). Picker of my treatments, then the
 * record of fumigation step by step, then signing and certificates once the treatment is closed. Same API as the standalone pages.
 */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApDb.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApAccess.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApSchema.php';

$db = NuDatabase::getInstance();
ApSchema::ensure($db);
ApDb::set($db);
$uid = (string)($_SESSION['nu_user_id'] ?? '');
$isAdmin = ApAccess::can($uid, 'admin.pages');
if (!$isAdmin && !ApAccess::can($uid, 'treatment.record')) {
    http_response_code(403);
    exit('<div style="padding:24px">Access denied.</div>');
}
$sets = ['compress.js', 'queue.js', 'signpad.js', 'signing.js', 'rof.js'];
?>
<link rel="stylesheet" href="assets/css/allpest-studio.css">
<div class="ap-scope">
  <h1>Treatment workspace</h1>
  <div id="pick" class="ap-card" hidden><h2>Choose a treatment</h2><div id="mine"></div></div>
  <div id="rof" hidden>
    <p><a href="#" id="backToList">&larr; All treatments</a> &middot; <a href="#" id="viewRecord">View the record of fumigation</a></p>
    <div class="ap-card"><div id="head"></div><p id="queue" class="ap-muted"></p><div id="locked" class="ap-alert" hidden></div></div>
    <div class="ap-card" id="s_pre" hidden><h2>1. Pre-treatment check</h2><div id="pre"></div></div>
    <div class="ap-card" id="s_dose" hidden><h2>2. Apply the gas</h2><div id="dose"></div></div>
    <div class="ap-card" id="s_read" hidden><h2>3. Gas readings</h2><div id="read"></div></div>
    <div class="ap-card" id="s_end" hidden><h2>4. Top-up, ventilation and TLV</h2><div id="end"></div></div>
    <div id="s_sign" data-admin="<?php echo $isAdmin ? 1 : 0; ?>" hidden>
      <div class="ap-card"><h2>5. Signing</h2><div id="state"></div></div>
      <div class="ap-card" id="fum"><h2>Fumigator signature</h2>
        <label class="ap-field"><span>Your password</span><input id="pw" type="password" autocomplete="current-password"></label>
        <label class="ap-field"><span>Authenticator code (if you use one)</span><input id="mfa" inputmode="numeric" autocomplete="one-time-code"></label>
        <button class="ap-btn" id="fsign">Sign as fumigator</button></div>
      <div class="ap-card" id="off"><h2>Supervising officer signature</h2>
        <p class="ap-muted">Hand the tablet to the officer. They sign with a finger or stylus.</p>
        <label class="ap-field"><span>Officer name</span><input id="on"></label><label class="ap-field"><span>Designation</span><input id="od" value="Biosecurity Officer"></label>
        <canvas id="pad" class="ap-sign"></canvas><button class="ap-btn alt" id="clr">Clear</button><button class="ap-btn" id="osign">Sign as officer</button></div>
<?php if ($isAdmin): ?>
      <div class="ap-card"><h2>Correct a recorded value</h2>
        <p class="ap-muted">A correction keeps the old version and needs both signatures again.</p>
        <label class="ap-field"><span>Field</span><select id="cf"><?php foreach (['dose_g', 'applied_g', 'height_m', 'depth_m', 'length_m', 'volume_m3', 'dose_rate_used', 'enclosure_type', 'scheduled_start'] as $f) { echo '<option>' . $f . '</option>'; } ?></select></label>
        <label class="ap-field"><span>New value</span><input id="cv"></label><label class="ap-field"><span>Reason (required)</span><textarea id="cr" rows="2"></textarea></label>
        <button class="ap-btn warn" id="corr">Save correction</button></div>
<?php endif; ?>
      <div class="ap-card"><h2>Certificates</h2><div id="certs"></div>
<?php if ($isAdmin): ?>
        <button class="ap-btn" id="issue">Issue certificates now</button><button class="ap-btn alt" id="resend">Send pending emails</button><a class="ap-btn alt" style="display:inline-block;text-decoration:none" id="pack" href="#">Audit pack (ZIP)</a>
<?php endif; ?>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  window.AP_API = 'api/allpest/';
  window.AP_PARAMS = window.AP_NEXT ? { id: window.AP_NEXT } : {};
  var openId = window.AP_NEXT || null;
  window.AP_NEXT = null;
  document.getElementById('viewRecord').addEventListener('click', function (e) { e.preventDefault(); if (openId) { window.AP_ROF_NEXT = openId; } NuApp.loadModule('allpest_rof'); });
  var files = <?php echo json_encode(array_merge(['ui.js'], $sets)); ?>, i = 0;
  document.getElementById('backToList').addEventListener('click', function (e) { e.preventDefault(); ApUi.open(null); });
  (function next() {
    if (i >= files.length) { return; }
    // ui.js must be present before the others run: load in order, one at a time (the studio re-injects module scripts asynchronously).
    var s = document.createElement('script'); s.async = false; s.src = 'assets/js/allpest/' + files[i++] + '?v=<?php echo (int)filemtime(dirname(__DIR__, 2) . '/assets/js/allpest/rof.js'); ?>';
    s.onload = next; s.onerror = function () { console.error('Could not load', s.src); };
    document.head.appendChild(s);
  })();
})();
</script>
