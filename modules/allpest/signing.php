<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . "/core/module_bootstrap.php";
require_once __DIR__ . "/_layout.php";
ap_page_start('Signing and certificates');
$isAdmin = ApAccess::can((string)($_SESSION['nu_user_id'] ?? ''), 'admin.pages');
?>
<div class="ap-card"><div id="state"></div></div>
<div id="s_sign" data-admin="<?php echo $isAdmin ? 1 : 0; ?>"><div class="ap-card" id="fum"><h2>Fumigator signature</h2>
  <label class="ap-field"><span>Your password</span><input id="pw" type="password" autocomplete="current-password"></label>
  <label class="ap-field"><span>Authenticator code (only if your account uses an authenticator app; 6 digits, otherwise leave empty)</span><input id="mfa" inputmode="numeric" autocomplete="one-time-code"></label>
  <button class="ap-btn" id="fsign">Sign as fumigator</button></div>
<div class="ap-card" id="off"><h2>Supervising officer signature</h2>
  <p class="ap-muted">Hand the tablet to the officer. They sign with a finger or stylus.</p>
  <label class="ap-field"><span>Officer name</span><input id="on"></label><label class="ap-field"><span>Designation</span><input id="od" value="Biosecurity Officer"></label>
  <canvas id="pad" class="ap-sign"></canvas><button class="ap-btn alt" id="clr">Clear</button><button class="ap-btn" id="osign">Sign as officer</button></div>
<?php if ($isAdmin): ?><div class="ap-card"><h2>Correct a recorded value</h2>
  <p class="ap-muted">A correction keeps the old version and needs both signatures again.</p>
  <label class="ap-field"><span>Field</span><select id="cf"><?php foreach (['dose_g', 'applied_g', 'height_m', 'depth_m', 'length_m', 'volume_m3', 'dose_rate_used', 'enclosure_type', 'scheduled_start'] as $f) { echo '<option>' . $f . '</option>'; } ?></select></label>
  <label class="ap-field"><span>New value</span><input id="cv"></label><label class="ap-field"><span>Reason (required)</span><textarea id="cr" rows="2"></textarea></label><button class="ap-btn warn" id="corr">Save correction</button></div><?php endif; ?>
<div class="ap-card"><h2>Certificates</h2><div id="certs"></div>
<?php if ($isAdmin): ?><button class="ap-btn" id="issue">Issue certificates now</button><button class="ap-btn alt" id="resend">Send pending emails</button><a class="ap-btn alt" style="display:inline-block;text-decoration:none" id="pack" href="#">Audit pack (ZIP)</a><?php endif; ?></div></div>
<script src="../../assets/js/allpest/signpad.js"></script><script src="../../assets/js/allpest/signing.js"></script>
<script>ApSigning.start(ApUi.qs('id'));</script>
<?php ap_page_end();
