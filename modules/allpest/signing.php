<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . "/core/module_bootstrap.php";
require_once __DIR__ . "/_layout.php";
ap_page_start('Signing and certificates');
$isAdmin = ApAccess::can((string)($_SESSION['nu_user_id'] ?? ''), 'admin.pages');
?>
<div class="ap-card"><div id="state"></div></div>
<div class="ap-card" id="fum"><h2>Fumigator signature</h2>
  <label class="ap-field"><span>Your password</span><input id="pw" type="password" autocomplete="current-password"></label>
  <label class="ap-field"><span>Authenticator code (if you use one)</span><input id="mfa" inputmode="numeric" autocomplete="one-time-code"></label>
  <button class="ap-btn" id="fsign">Sign as fumigator</button></div>
<div class="ap-card" id="off"><h2>Supervising officer signature</h2>
  <p class="ap-muted">Hand the tablet to the officer. They sign with a finger or stylus.</p>
  <label class="ap-field"><span>Officer name</span><input id="on"></label><label class="ap-field"><span>Designation</span><input id="od" value="Biosecurity Officer"></label>
  <canvas id="pad" class="ap-sign"></canvas><button class="ap-btn alt" id="clr">Clear</button><button class="ap-btn" id="osign">Sign as officer</button></div>
<div class="ap-card"><h2>Correct a recorded value</h2>
  <p class="ap-muted">A correction keeps the old version and needs both signatures again.</p>
  <label class="ap-field"><span>Field</span><select id="cf"><?php foreach (['dose_g', 'applied_g', 'height_m', 'depth_m', 'length_m', 'volume_m3', 'dose_rate_used', 'enclosure_type', 'scheduled_start'] as $f) { echo '<option>' . $f . '</option>'; } ?></select></label>
  <label class="ap-field"><span>New value</span><input id="cv"></label><label class="ap-field"><span>Reason (required)</span><textarea id="cr" rows="2"></textarea></label><button class="ap-btn warn" id="corr">Save correction</button></div>
<div class="ap-card"><h2>Certificates</h2><div id="certs"></div>
<?php if ($isAdmin): ?><button class="ap-btn" id="issue">Issue certificates now</button><button class="ap-btn alt" id="resend">Send pending emails</button><a class="ap-btn alt" style="display:inline-block;text-decoration:none" id="pack" href="#">Audit pack (ZIP)</a><?php endif; ?></div>
<script>
var id=ApUi.qs('id'),pad;
function load(){ApUi.get('sign.php',{treatment_id:id}).then(function(s){
 document.getElementById('state').innerHTML='<p>'+(s.locked?'Signed and locked.':'Not signed yet.')+' '+(s.fully_signed?'<strong>Both signatures are in.</strong>':'')+'</p>'+(s.signatures||[]).map(function(x){return '<p>'+ApUi.esc(x.role)+': '+ApUi.esc(x.name)+' - '+ApUi.fiji(x.signed_at)+'</p>';}).join('');},ApUi.fail);
 <?php if ($isAdmin): ?>ApUi.get('certificates.php',{treatment_id:id}).then(function(r){ApUi.table(document.getElementById('certs'),r,[{label:'Container',key:'container_no'},{label:'Result',render:function(x){return '<span class="ap-badge '+ApUi.esc(x.result)+'">'+ApUi.esc(x.result||'-')+'</span>';}},{label:'Certificate',render:function(x){return ApUi.esc(x.number||'-');}},{label:'Emailed',render:function(x){return x.emailed_at?ApUi.fiji(x.emailed_at):(x.email_pending?'pending':'');}},{label:'Release',render:function(x){return ApUi.esc(typeof x.release==='object'?JSON.stringify(x.release):x.release);}}]);},ApUi.fail);<?php else: ?>document.getElementById('certs').innerHTML='<p class="ap-muted">Certificates are issued by the office once both signatures are in.</p>';<?php endif; ?>
}
document.getElementById('fsign').onclick=function(){ApUi.post('sign.php',{action:'fumigator_sign',treatment_id:id,password:document.getElementById('pw').value,mfa_code:document.getElementById('mfa').value}).then(function(){document.getElementById('pw').value='';ApUi.toast('Signed');load();},ApUi.fail);};
document.getElementById('osign').onclick=function(){var png=pad.toPng();if(!png){ApUi.toast('The officer has not signed yet',true);return;}
 ApUi.post('sign.php',{action:'officer_sign',treatment_id:id,name:document.getElementById('on').value,designation:document.getElementById('od').value,png:png}).then(function(){pad.clear();ApUi.toast('Signed');load();},ApUi.fail);};
document.getElementById('clr').onclick=function(){pad.clear();};
document.getElementById('corr').onclick=function(){var ch={};ch[document.getElementById('cf').value]=document.getElementById('cv').value;ApUi.post('sign.php',{action:'correct',treatment_id:id,changes:JSON.stringify(ch),reason:document.getElementById('cr').value}).then(function(r){ApUi.toast('Corrected: version '+r.version+'. Sign again.');load();},ApUi.fail);};
<?php if ($isAdmin): ?>
document.getElementById('issue').onclick=function(){ApUi.post('certificates.php',{action:'issue',treatment_id:id}).then(function(r){ApUi.toast('Issued '+(r.issued||[]).length+', waiting '+(r.skipped||[]).length);load();},ApUi.fail);};
document.getElementById('resend').onclick=function(){ApUi.post('certificates.php',{action:'resend_pending'}).then(function(r){ApUi.toast('Sent '+r.sent);load();},ApUi.fail);};
document.getElementById('pack').href='../../api/allpest/audit_pack.php?treatment_id='+encodeURIComponent(id);
<?php endif; ?>
</script>
<script src="../../assets/js/allpest/signpad.js"></script>
<script>pad=ApSignPad(document.getElementById('pad'));load();</script>
<?php ap_page_end();
