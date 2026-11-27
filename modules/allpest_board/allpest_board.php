<?php
declare(strict_types=1);
/**
 * Request status board, loaded inline in the StudioX shell (sidebar: All Pest > Request Board). One column per status.
 * Clicking a card opens a panel with the request's containers and the next step: Confirm (new), then Create treatment
 * (confirmed or scheduled), which goes through the engine so the steps and the audit trail stay in order.
 */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApDb.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApAccess.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApSchema.php';

$db = NuDatabase::getInstance();
ApSchema::ensure($db);
ApDb::set($db);
if (!ApAccess::can((string)($_SESSION['nu_user_id'] ?? ''), 'admin.requests')) {
    http_response_code(403);
    exit('<div style="padding:24px">Access denied.</div>');
}
$cols = ['new' => 'New', 'confirmed' => 'Confirmed', 'scheduled' => 'Scheduled', 'in_progress' => 'In progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
$rows = $db->fetchAll('SELECT id, reference, customer_name, status, requested_date FROM ap_requests ORDER BY created_at DESC LIMIT 500');
$by = array_fill_keys(array_keys($cols), []);
foreach ($rows as $r) { $by[isset($by[$r['status']]) ? $r['status'] : 'new'][] = $r; }
$e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<link rel="stylesheet" href="assets/css/allpest-studio.css">
<div class="ap-scope">
  <h1>Request board</h1>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;align-items:start">
  <?php foreach ($cols as $code => $label): ?>
    <div class="ap-card"><h2><?php echo $e($label); ?> <span class="ap-muted">(<?php echo count($by[$code]); ?>)</span></h2>
      <?php foreach ($by[$code] as $r): ?>
        <p><a href="#" class="ap-open" data-id="<?php echo $e($r['id']); ?>"><strong><?php echo $e($r['reference']); ?></strong></a><br>
        <?php echo $e($r['customer_name']); ?><?php echo $r['requested_date'] ? '<br><span class="ap-muted">' . $e($r['requested_date']) . '</span>' : ''; ?></p>
      <?php endforeach; ?>
      <?php if (!$by[$code]): ?><p class="ap-muted">Nothing here.</p><?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>
  <div class="ap-card" id="reqpanel" hidden style="margin-top:12px"></div>
</div>
<script>
(function () {
  var panel = document.getElementById('reqpanel');
  var API = 'api/allpest/requests.php', OPT = 'api/allpest/rof.php?options=1';
  function esc(v) { var d = document.createElement('div'); d.textContent = v == null ? '' : String(v); return d.innerHTML; }
  function get(url) { return fetch(url, {credentials: 'same-origin'}).then(function (r) { return r.json().then(function (j) { if (!r.ok) { throw new Error(j.error || 'Failed'); } return j; }); }); }
  function post(data) { return fetch(API, {method: 'POST', body: new URLSearchParams(data), credentials: 'same-origin'}).then(function (r) { return r.json().then(function (j) { if (!r.ok) { throw new Error(j.error || 'Not saved'); } return j; }); }); }
  function msg(t, bad) { var m = panel.querySelector('.msg'); if (m) { m.textContent = t; m.style.color = bad ? '#b91c1c' : '#166534'; } }
  // Fiji has no daylight saving at present: local time is UTC+12.
  function fijiToUtc(v) { var d = new Date(v + ':00Z'); if (isNaN(d)) { return ''; } d = new Date(d.getTime() - 12 * 3600 * 1000); return d.toISOString().slice(0, 19).replace('T', ' '); }
  function open(id) {
    panel.hidden = false; panel.innerHTML = 'Loading…';
    Promise.all([get(API + '?id=' + encodeURIComponent(id)), get(OPT)]).then(function (res) { render(res[0], res[1]); }).catch(function (e) { panel.textContent = e.message; });
  }
  function render(r, o) {
    var h = '<h2>' + esc(r.reference) + ' · ' + esc(r.customer_name) + ' <span class="ap-muted">(' + esc(r.status) + ')</span></h2>';
    h += '<p>' + esc(r.site_address || '') + '</p><p class="ap-muted">Containers: ' + (r.consignments.length ? r.consignments.map(function (c) { return esc(c.container_no) + (c.treatment_id ? ' ✓' : ''); }).join(', ') : 'none yet (they are imported when you confirm)') + '</p>';
    if (r.treatments.length) { h += '<p>Records of fumigation (one per container when un-sheeted):<br>' + r.treatments.map(function (t) { return '<a href="#" class="ap-rof" data-id="' + esc(t.id) + '">' + esc((t.containers || []).join(', ') || t.rof_type) + ' · ' + esc(t.status) + '</a>'; }).join('<br>') + '</p>'; }
    if (r.status === 'new') {
      h += '<p><button class="ap-btn" id="ap-confirm" type="button">Confirm request</button></p>';
    } else if (r.status === 'confirmed' || r.status === 'scheduled') {
      var free = r.consignments.filter(function (c) { return !c.treatment_id; });
      if (!free.length) { h += '<p class="ap-muted">Every container is already in a treatment.</p>'; }
      else {
        h += '<h3>Create a treatment (one record of fumigation)</h3><div id="tform">';
        h += '<label class="ap-field"><span>Containers</span>' + free.map(function (c) { return '<label style="display:block"><input type="checkbox" class="cid" value="' + esc(c.id) + '" checked> ' + esc(c.container_no) + ' · ' + esc(c.commodity) + '</label>'; }).join('') + '</label>';
        h += '<label class="ap-field"><span>Enclosure</span><select id="t_enc"><option value="container">Un-sheeted container(s) (one ROF per container)</option><option value="container_stack">Stack of containers under sheet</option><option value="sheeted">Sheeted enclosure</option><option value="chamber">Fumigation chamber</option></select></label>';
        h += '<label class="ap-field"><span>Record type</span><select id="t_rof"><option value="general">Non-perishable</option><option value="perishable">Perishable</option></select></label>';
        h += '<label class="ap-field"><span>Fumigation type</span><select id="t_fum"><option value="ambient">Ambient</option><option value="controlled">Controlled</option></select></label>';
        h += '<label class="ap-field"><span>Scheduled start (Fiji time)</span><input id="t_start" type="datetime-local"></label>';
        h += '<label class="ap-field"><span>Technician</span><select id="t_tech">' + o.technicians.map(function (x) { return '<option value="' + esc(x.id) + '">' + esc(x.full_name) + '</option>'; }).join('') + '</select></label>';
        h += '<label class="ap-field"><span>Supervising officer</span><select id="t_off">' + o.officers.map(function (x) { return '<option value="' + esc(x.id) + '">' + esc(x.full_name) + '</option>'; }).join('') + '</select></label>';
        h += '<label class="ap-field"><span>Treatment schedule</span><select id="t_sch"></select></label>';
        h += '<p><button class="ap-btn" id="ap-sched" type="button">Create treatment</button></p></div>';
      }
    }
    h += '<p class="msg"></p><p><a href="#" id="ap-form">Open the request form</a> · <a href="#" id="ap-close">Close</a></p>';
    panel.innerHTML = h;
    var sch = document.getElementById('t_sch'), rof = document.getElementById('t_rof');
    function fillSchedules() { if (!sch) { return; } sch.innerHTML = o.schedules.filter(function (s) { return s.treatment_type === rof.value; }).map(function (s) { return '<option value="' + esc(s.id) + '">' + esc(s.name) + '</option>'; }).join(''); }
    if (rof) { rof.addEventListener('change', fillSchedules); fillSchedules(); }
    var c = document.getElementById('ap-confirm');
    if (c) { c.onclick = function () { post({action: 'confirm', request_id: r.id}).then(function () { NuApp.loadModule('allpest_board'); }).catch(function (e) { msg(e.message, true); }); }; }
    var b = document.getElementById('ap-sched');
    if (b) {
      b.onclick = function () {
        var ids = Array.prototype.map.call(panel.querySelectorAll('.cid:checked'), function (x) { return x.value; });
        var data = {action: 'schedule', request_id: r.id, rof_type: rof.value, enclosure_type: document.getElementById('t_enc').value, fumigation_type: document.getElementById('t_fum').value, scheduled_start: fijiToUtc(document.getElementById('t_start').value), technician_id: document.getElementById('t_tech').value, officer_id: document.getElementById('t_off').value, schedule_id: sch.value};
        var p = new URLSearchParams(data); ids.forEach(function (i) { p.append('container_ids[]', i); });
        fetch(API, {method: 'POST', body: p, credentials: 'same-origin'}).then(function (x) { return x.json().then(function (j) { if (!x.ok) { throw new Error(j.error || 'Not saved'); } return j; }); })
          .then(function (j) { window.AP_ROF_NEXT = j.treatment_id; NuApp.loadModule('allpest_rof'); }).catch(function (e) { msg(e.message, true); });
      };
    }
    panel.querySelectorAll('.ap-rof').forEach(function (a) { a.addEventListener('click', function (ev) { ev.preventDefault(); window.AP_ROF_NEXT = a.dataset.id; NuApp.loadModule('allpest_rof'); }); });
    document.getElementById('ap-form').onclick = function (ev) { ev.preventDefault(); NuApp.editRecord('ap_requests', r.id, 'Fumigation Requests', 'inline'); };
    document.getElementById('ap-close').onclick = function (ev) { ev.preventDefault(); panel.hidden = true; };
  }
  document.querySelectorAll('.ap-open').forEach(function (a) { a.addEventListener('click', function (ev) { ev.preventDefault(); open(a.dataset.id); }); });
})();
</script>
