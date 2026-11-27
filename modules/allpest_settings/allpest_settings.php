<?php
declare(strict_types=1);
/** All Pest settings, loaded inline in the StudioX shell (sidebar: All Pest > Settings). Saves each field through api/allpest/settings.php. */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApAccess.php';
if (!ApAccess::can((string)($_SESSION['nu_user_id'] ?? ''), 'admin.settings')) {
    http_response_code(403);
    exit('<div style="padding:24px">Access denied.</div>');
}
$fields = [
    'Company' => [['company_name', 'Company name', 'text'], ['company_address', 'Company address', 'text'], ['company_phone', 'Company phone', 'text'], ['company_email', 'Company email (printed on documents)', 'email'], ['company_website', 'Company website', 'text'], ['treatment_provider_id', 'Treatment provider ID', 'text'], ['staff_email', 'Office email (request notifications)', 'email']],
    'Invoicing (NuvisERPX)' => [['erpx.base_url', 'ERPX address', 'text'], ['erpx.api_key', 'ERPX API key', 'password'], ['erpx.product.general', 'ERPX product code: general fumigation', 'text'],
        ['erpx.product.perishable', 'ERPX product code: perishable fumigation', 'text'], ['erpx.tax_rate', 'Tax rate (%)', 'text'], ['invoice.mode', 'Invoice release', 'mode']],
    'Certificates and stock' => [['certificate_admin_verify', 'Admin verifies certificates (1 = yes)', 'text'], ['certificate_target', 'Certificate target', 'text'], ['certificate_additional_declarations', 'Certificate additional declarations', 'text'], ['stock.low_threshold_g', 'Low stock alert (grams)', 'text']],
];
?>
<link rel="stylesheet" href="assets/css/allpest-studio.css">
<div class="ap-scope">
  <h1>All Pest settings</h1>
  <p id="msg" class="ap-muted"></p>
  <?php foreach ($fields as $title => $list): ?>
  <div class="ap-card"><h2><?php echo htmlspecialchars($title); ?></h2>
    <?php foreach ($list as [$k, $label, $type]): ?>
      <label class="ap-field"><span><?php echo htmlspecialchars($label); ?></span>
      <?php if ($type === 'mode'): ?>
        <select data-k="<?php echo $k; ?>"><option value="reviewed">Reviewed by office first</option><option value="auto">Send automatically</option></select>
      <?php else: ?>
        <input data-k="<?php echo $k; ?>" type="<?php echo $type; ?>" autocomplete="off">
      <?php endif; ?></label>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
  <div class="ap-card"><h2>Company logo</h2>
    <p class="ap-muted">Printed at the top of every record of fumigation and certificate, with the company details above.</p>
    <p><img id="logo-prev" alt="Company logo" style="max-height:80px;display:none"></p>
    <input type="file" id="logo-file" accept="image/png,image/jpeg"> <button class="ap-btn" id="logo-up" type="button">Upload logo</button> <button class="ap-btn" id="logo-clear" type="button">Remove logo</button></div>
  <div class="ap-card"><h2>Set up forms and menu</h2>
    <p class="ap-muted">Creates any missing All Pest forms, menu entries and the customer request link. Safe to run again.</p>
    <button class="ap-btn" id="install">Set up / repair</button> <p id="link"></p></div>
</div>
<script>
(function () {
  var api = 'api/allpest/settings.php', msg = document.getElementById('msg');
  function say(t, bad) { msg.textContent = t; msg.style.color = bad ? '#b91c1c' : ''; }
  fetch(api, {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (j) {
    document.querySelectorAll('[data-k]').forEach(function (el) { el.value = (j.settings || {})[el.dataset.k] || (el.dataset.k === 'invoice.mode' ? 'reviewed' : ''); });
  }).catch(function () { say('Could not load settings.', true); });
  document.querySelectorAll('[data-k]').forEach(function (el) {
    el.addEventListener('change', function () {
      var b = new URLSearchParams({key: el.dataset.k, value: el.value});
      fetch(api, {method: 'POST', body: b, credentials: 'same-origin'}).then(function (r) { return r.json().then(function (j) { return [r.ok, j]; }); })
        .then(function (x) { say(x[0] ? 'Saved.' : (x[1].error || 'Not saved.'), !x[0]); }).catch(function () { say('Not saved: no connection.', true); });
    });
  });
  var prev = document.getElementById('logo-prev');
  function showLogo(on) { prev.style.display = on ? '' : 'none'; if (on) { prev.src = api + '?logo=1&_=' + Date.now(); } }
  fetch(api, {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (j) { showLogo(!!(j.settings && j.settings.has_logo)); }).catch(function () {});
  document.getElementById('logo-up').addEventListener('click', function () {
    var f = document.getElementById('logo-file').files[0]; if (!f) { say('Choose a logo image first.', true); return; }
    var fd = new FormData(); fd.append('action', 'logo'); fd.append('file', f);
    fetch(api, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json().then(function (j) { return [r.ok, j]; }); })
      .then(function (x) { say(x[0] ? 'Logo saved.' : (x[1].error || 'Not saved.'), !x[0]); if (x[0]) { showLogo(true); } }).catch(function () { say('Not saved: no connection.', true); });
  });
  document.getElementById('logo-clear').addEventListener('click', function () {
    fetch(api, {method: 'POST', body: new URLSearchParams({action: 'logo_clear'}), credentials: 'same-origin'}).then(function () { say('Logo removed.'); showLogo(false); });
  });
  document.getElementById('install').addEventListener('click', function () {
    fetch(api, {method: 'POST', body: new URLSearchParams({action: 'install'}), credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (j) {
      say('Done. Forms created: ' + ((j.forms_created || []).length || 0) + '. Reload to see new menu entries.');
      if (j.public_form) { var u = new URL(j.public_form, location.href.replace(/[^\/]*$/, '')).href; document.getElementById('link').textContent = 'Customer request link: ' + u; }
    }).catch(function () { say('Setup failed.', true); });
  });
})();
</script>
