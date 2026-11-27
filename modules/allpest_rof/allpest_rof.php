<?php
declare(strict_types=1);
/**
 * Record of fumigation, laid out as the methodology v3.0 template (sections A to E), loaded inline in the StudioX shell
 * (sidebar: All Pest > Record of Fumigation). Sections B and C take the facts the template asks for, perishable records get the
 * section D temperature table. Gas readings, dosing, top-up, TLV and signing stay in the Treatment Workspace. Printable.
 */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApAccess.php';
$uid = (string)($_SESSION['nu_user_id'] ?? '');
if (!ApAccess::can($uid, 'admin.pages') && !ApAccess::can($uid, 'treatment.record')) {
    http_response_code(403);
    exit('<div style="padding:24px">Access denied.</div>');
}
?>
<link rel="stylesheet" href="assets/css/allpest-studio.css">
<style>
.ap-scope .rof table{width:100%;border-collapse:collapse;margin:0 0 12px}.ap-scope .rof td,.ap-scope .rof th{border:1px solid #94a3b8;padding:5px 7px;vertical-align:top;font-size:13px}
.ap-scope .rof th.sec{background:#e2e8f0;text-align:left}.ap-scope .rof .lbl{color:#475569;font-size:11px;display:block}.ap-scope .rof input,.ap-scope .rof select,.ap-scope .rof textarea{width:100%;box-sizing:border-box}
.ap-scope .rof input[type=checkbox],.ap-scope .rof input[type=radio]{width:auto}.ap-scope .rof .ro{background:#f8fafc}
@media print{.ap-scope .noprint{display:none!important}}
</style>
<div class="ap-scope">
  <h1>Record of fumigation</h1>
  <div class="ap-card noprint" id="pick"><h2>Choose a treatment</h2><div id="mine">Loading…</div></div>
  <div class="ap-card noprint" id="newcard" hidden><h2>Start a new record</h2>
    <p class="ap-muted" id="needs"></p>
    <div id="newform">
      <label class="ap-field"><span>Client name</span><input id="n_name"></label>
      <label class="ap-field"><span>Client email (optional)</span><input id="n_email" type="email"></label>
      <label class="ap-field"><span>Client address</span><input id="n_addr"></label>
      <label class="ap-field"><span>Place of fumigation (site address)</span><input id="n_site"></label>
      <label class="ap-field"><span>Container numbers (one per line)</span><textarea id="n_cont" rows="3"></textarea></label>
      <label class="ap-field"><span>Record type</span><select id="n_type"><option value="general">Non-perishable</option><option value="perishable">Perishable</option></select></label>
      <label class="ap-field"><span>Treatment schedule</span><select id="n_sched"></select></label>
      <label class="ap-field"><span>Fumigator in charge</span><select id="n_tech"></select></label>
      <label class="ap-field"><span>Supervising officer</span><select id="n_off"></select></label>
      <label class="ap-field"><span>Planned start</span><input id="n_start" type="datetime-local"></label>
      <label class="ap-field"><span>Enclosure</span><select id="n_enc"><option value="container">Un-sheeted container(s)</option><option value="container_stack">Stack of containers under sheet</option><option value="sheeted">Sheeted enclosure</option><option value="chamber">Fumigation chamber</option><option value="other">Other</option></select></label>
      <label class="ap-field"><span>Temperature</span><select id="n_fum"><option value="ambient">Ambient</option><option value="controlled">Controlled</option></select></label>
      <button class="ap-btn" id="n_go">Create record</button>
    </div></div>
  <p id="msg" class="ap-muted noprint"></p>
  <p class="noprint" id="bar" hidden><button class="ap-btn alt" id="b_back" type="button">&larr; All records / start a new record</button></p>
  <div id="doc" class="rof" hidden></div>
</div>
<script>
(function () {
  var TAPI = 'api/allpest/treatments.php', API = 'api/allpest/rof.php', doc = null, tid = null, canNew = false;
  var $ = function (id) { return document.getElementById(id); };
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function say(t, bad) { var m = $('msg'); m.textContent = t; m.style.color = bad ? '#b91c1c' : ''; }
  function when(s) { return s ? new Date(String(s).replace(' ', 'T') + 'Z').toLocaleString() : ''; }
  function v(x) { return x == null ? '' : esc(x); }
  function lbl(l, inner) { return '<td><span class="lbl">' + l + '</span>' + inner + '</td>'; }
  function post(data) {
    return fetch(API, {method: 'POST', body: new URLSearchParams(data), credentials: 'same-origin'}).then(function (r) { return r.json().then(function (j) { if (!r.ok) { throw new Error(j.error || 'Not saved'); } return j; }); });
  }
  function load(id) {
    tid = id;
    fetch(API + '?id=' + encodeURIComponent(id), {credentials: 'same-origin'}).then(function (r) { return r.json().then(function (j) { if (!r.ok) { throw new Error(j.error || 'Not found'); } return j; }); })
      .then(function (d) { doc = d; render(); }).catch(function (e) { say(e.message, true); });
  }
  function yn(name, val, dis) {
    return '<label><input type="radio" name="' + name + '" value="1"' + (val === 1 ? ' checked' : '') + (dis ? ' disabled' : '') + '> Yes</label> <label><input type="radio" name="' + name + '" value="0"' + (val === 0 ? ' checked' : '') + (dis ? ' disabled' : '') + '> No</label>';
  }
  function render() {
    var d = doc, A = d.A, B = d.B, C = d.C, D = d.D, E = d.E, dis = d.locked, per = d.variant === 'perishable', h = '';
    var inp = function (id, val, type) { return '<input id="' + id + '" type="' + (type || 'text') + '" value="' + v(val) + '"' + (dis ? ' disabled' : '') + '>'; };
    h += '<h2>' + esc(d.title) + '</h2>' + (dis ? '<p class="ap-alert">This record is signed and read-only.</p>' : '');
    h += '<table><tr><th class="sec" colspan="2">Section A: Fumigator in charge</th></tr><tr>' + lbl('Full name', '<span class="ro">' + v(A.full_name) + '</span>') + lbl('Accreditation number', v(A.accreditation_no)) + '</tr></table>';
    var tg = function (k, t) { return '<label><input type="checkbox" class="tg" value="' + k + '"' + (B.targets.indexOf(k) >= 0 ? ' checked' : '') + (dis ? ' disabled' : '') + '> ' + t + '</label> '; };
    h += '<table><tr><th class="sec" colspan="2">Section B: Job details</th></tr><tr>' + lbl('Treatment provider ID', v(B.provider_id)) + lbl('Client name / details', '<span style="white-space:pre-line">' + v(B.client) + '</span>') + '</tr>' +
      '<tr>' + lbl('Job identification number (this record)', v(B.job_no)) + lbl('Description of consignment', '<textarea id="f_consignment_description" rows="2"' + (dis ? ' disabled' : '') + '>' + v(B.description) + '</textarea>') + '</tr>' +
      '<tr>' + lbl('Street address', inp('f_loc_street', B.street)) + lbl('Target of fumigation', tg('commodity', 'Commodity') + tg('container', 'Container') + tg('packaging', 'Packaging') + tg('other', 'Other') + inp('f_target_other', B.target_other)) + '</tr>' +
      '<tr>' + lbl('Suburb / town / city', inp('f_loc_suburb', B.suburb)) + lbl('Country / postcode', inp('f_loc_country', B.country) + inp('f_loc_postcode', B.postcode)) + '</tr>' +
      '<tr>' + lbl('Port of loading', inp('f_port_of_loading', B.port_of_loading)) + lbl('Order reference', v(B.order_no)) + '</tr>' +
      '<tr><td colspan="2"><span class="lbl">Consignment identification / container numbers</span>' + B.containers.map(function (c) { return v(c.container_no) + (c.seal_no ? ' (seal ' + v(c.seal_no) + ')' : ''); }).join(', ') + '</td></tr></table>';
    var enc = Object.keys({sheeted: 1, chamber: 1, container: 1, container_stack: 1, other: 1}).map(function (k) { var t = {sheeted: 'Sheeted enclosure', chamber: 'Fumigation chamber', container: 'Un-sheeted container(s)', container_stack: 'Stack of containers under sheet', other: 'Other'}[k]; return (C.enclosure_type === k ? '<strong>' + t + '</strong>' : t); }).join(' / ');
    h += '<table><tr><th class="sec" colspan="4">Section C: Fumigation details</th></tr>' +
      '<tr>' + lbl('Specified treatment schedule', v(C.schedule.name)) + lbl('Dose rate (g/m3)', v(C.schedule.dose_rate)) + lbl('Exposure period (hrs)', v(C.schedule.exposure_hours)) + lbl('Temperature (°C)', v(C.schedule.min_temp_c)) + '</tr>' +
      '<tr>' + lbl('Enclosure type', enc) + lbl('Other enclosure (describe)', inp('f_enclosure_other', C.enclosure_other)) + lbl('Length / height / width (m)', v(C.length_m) + ' / ' + v(C.height_m) + ' / ' + v(C.width_m)) + lbl('Total volume (m3)', v(C.volume_m3)) + '</tr>' +
      '<tr>' + lbl('Was the consignment suitable for fumigation?', yn('f_suitable', C.suitable, dis)) + lbl('If no, what action?', '<textarea id="f_remedial_action" rows="2"' + (dis ? ' disabled' : '') + '>' + v(C.remedial_action) + '</textarea>') +
        lbl('Dose rate used (g/m3)', v(C.dose_rate_used)) + lbl('Calculated dose (g)', v(C.calculated_dose_g)) + '</tr>' +
      '<tr>' + lbl('Fumigation type', C.fumigation_type === 'controlled' ? 'Controlled temperature' : 'Ambient temperature') + lbl('Forecast min temp (°C)', v(C.forecast_min_c)) +
        lbl('Min enclosure temp (°C) if controlled', inp('f_min_enclosure_temp_c', C.min_enclosure_temp_c, 'number')) + lbl('Heaters used?', yn('f_heaters_used', C.heaters_used, dis)) + '</tr>' +
      '<tr>' + lbl('Chloropicrin', C.chloropicrin ? 'Yes, ' + v(C.chloropicrin_pct) + '%' : 'No') + lbl('Amount CH3Br applied (g)', v(C.applied_g)) +
        (per ? lbl('Retention rate (%)', inp('f_retention_pct', C.retention_pct, 'number')) + lbl('or CT product', inp('f_ct_product', C.ct_product, 'number')) : '<td colspan="2"></td>') + '</tr>' +
      (per ? '<tr>' + lbl('Temperature probe location', '<select id="f_probe_location"' + (dis ? ' disabled' : '') + '><option value="">Choose</option><option value="inside_packaging"' + (C.probe_location === 'inside_packaging' ? ' selected' : '') + '>Inside packaging</option><option value="pulp"' + (C.probe_location === 'pulp' ? ' selected' : '') + '>Inserted into pulp</option></select>') +
        lbl('Load factor maximum (%)', inp('f_load_factor_max', C.load_factor_max, 'number')) + lbl('Load factor estimated (%)', inp('f_load_factor_est', C.load_factor_est, 'number')) + '<td></td></tr>' : '') + '</table>';
    if (!dis) { h += '<p class="noprint"><button class="ap-btn" id="saveDet">Save sections B and C</button></p>'; }
    h += '<table><tr><th class="sec" colspan="12">Section D: Concentration' + (per ? ' and temperature' : '') + ' readings</th></tr><tr><td colspan="12"><span class="lbl">Serial number(s) of monitoring device(s)</span>' + D.serials.map(v).join(', ') + '</td></tr>' +
      '<tr><td colspan="12"><span class="lbl">Fumigant injection finished</span>' + when(D.injected_at) + '</td></tr>' +
      '<tr><th>Stage</th><th>Date and time</th><th>Container</th><th>Locations (ppm or g/m3 as taken)</th><th>Equilibrium %</th><th>Standard (g/m3)</th><th>Initials</th></tr>';
    var cn = {}; B.containers.forEach(function (c) { cn[c.id] = c.container_no; });
    D.reading_sets.forEach(function (s) {
      var by = {}; s.values.forEach(function (x) { (by[x.container_id] = by[x.container_id] || []).push(x.location + ': ' + x.value); });
      var ids = Object.keys(by); if (!ids.length) { ids = ['']; by[''] = []; }
      ids.forEach(function (id, i) { h += '<tr><td>' + (i ? '' : v(s.stage)) + '</td><td>' + (i ? '' : when(s.taken_at)) + '</td><td>' + v(cn[id] || '') + '</td><td>' + by[id].map(esc).join('; ') + '</td><td>' + (i ? '' : v(s.equilibrium_pct)) + '</td><td>' + (i ? '' : v(s.standard)) + '</td><td>' + (i ? '' : v(s.initials)) + '</td></tr>'; });
    });
    if (!D.reading_sets.length) { h += '<tr><td colspan="7" class="ap-muted">No readings recorded yet. Record them in the Treatment Workspace.</td></tr>'; }
    h += '<tr><td colspan="12"><span class="lbl">Top-up</span>' + (D.topup_at ? v(D.topup_g) + ' g at ' + when(D.topup_at) : 'None') + '</td></tr>' +
      '<tr><td colspan="12"><span class="lbl">Enclosure ventilation start</span>' + when(D.ventilation_at) + '</td></tr>' +
      '<tr><td colspan="12"><span class="lbl">Final TLV readings (ppm)</span>' + D.tlv.map(function (t) { return v(t.container_no) + ': ' + (t.ppm == null ? '-' : v(t.ppm)) + (t.at ? ' at ' + when(t.at) : ''); }).join('; ') + '</td></tr></table>';
    if (per) {
      h += '<table><tr><th class="sec" colspan="10">Temperature readings (°C), probes 1 to 7</th></tr><tr><th>Date and time</th><th>1</th><th>2</th><th>3</th><th>4</th><th>5</th><th>6</th><th>7</th><th>Initials</th></tr>';
      D.temperatures.forEach(function (t) { h += '<tr><td>' + when(t.taken_at) + '</td>' + [1, 2, 3, 4, 5, 6, 7].map(function (n) { return '<td>' + v(t.values[n]) + '</td>'; }).join('') + '<td>' + v(t.initials) + '</td></tr>'; });
      if (!dis) { h += '<tr class="noprint"><td><input id="t_at" type="datetime-local"></td>' + [1, 2, 3, 4, 5, 6, 7].map(function (n) { return '<td><input class="t_v" data-n="' + n + '" inputmode="decimal"></td>'; }).join('') + '<td><input id="t_ini" maxlength="6"></td></tr>'; }
      h += '</table>' + (dis ? '' : '<p class="noprint"><button class="ap-btn" id="addTemp">Add temperature row</button></p>');
    }
    h += '<table><tr><th class="sec" colspan="3">Section E: Fumigator declaration</th></tr><tr><td colspan="3">I, the fumigator-in-charge declare that the fumigation was conducted in accordance with the treatment schedule and all the requirements in the Methyl Bromide Fumigation Methodology, and the information I have provided is true and correct.</td></tr>' +
      E.signatures.map(function (s) { return '<tr><td>' + v(s.role) + '</td><td>' + v(s.signer_name) + (s.signer_designation ? ', ' + v(s.signer_designation) : '') + '</td><td>' + when(s.signed_at) + '</td></tr>'; }).join('') +
      (E.signatures.length ? '' : '<tr><td colspan="3" class="ap-muted">Not signed yet. Signing is in the Treatment Workspace.</td></tr>') +
      '<tr><td>Fumigation result</td><td colspan="2">' + E.results.map(function (r) { return v(r.container_no) + ': ' + (r.result ? v(r.result) : 'pending'); }).join('; ') + '</td></tr>' +
      '<tr><td>Government officer if supervised</td><td colspan="2">' + v(E.officer.full_name || '') + (E.officer.designation ? ', ' + v(E.officer.designation) : '') + '</td></tr></table>';
    var nextStep = {planned: 'Do the pre-treatment check.', prechecked: 'Apply the gas and record the dose.', dosed: 'Take the start-point readings.', exposure: 'Take exposure readings, then top-up if needed and start ventilation.', ventilating: 'Record the TLV readings, then close the treatment.', closed: 'Sign the record and issue certificates.'}[d.status] || '';
    h += '<p class="noprint">Status: <strong>' + esc(d.status) + '</strong>' + (nextStep && !dis ? '. Next: ' + esc(nextStep) : '') + '</p>';
    h += '<p class="noprint">' + (dis ? '' : '<button class="ap-btn" id="goWork">Record dosing, readings, top-up and TLV</button> ') + '<button class="ap-btn" onclick="window.print()">Print</button> <a class="ap-btn" href="api/allpest/rof.php?id=' + encodeURIComponent(tid) + '&pdf=1">Download PDF</a> <button class="ap-btn" id="backList">All records</button></p>';
    $('doc').innerHTML = h; $('doc').hidden = false; $('pick').hidden = true; $('newcard').hidden = true; $('bar').hidden = false;
    var gw = $('goWork'); if (gw) { gw.onclick = function () { window.AP_NEXT = tid; NuApp.loadModule('allpest_work'); }; }
    var bl = $('backList'); if (bl) { bl.onclick = function () { NuApp.loadModule('allpest_rof'); }; }
    var sd = $('saveDet'); if (sd) { sd.onclick = saveDetails; }
    var at = $('addTemp'); if (at) { at.onclick = addTemp; }
  }
  function radio(n) { var el = document.querySelector('input[name="' + n + '"]:checked'); return el ? el.value : ''; }
  function saveDetails() {
    var f = {consignment_description: $('f_consignment_description').value, loc_street: $('f_loc_street').value, loc_suburb: $('f_loc_suburb').value, loc_country: $('f_loc_country').value, loc_postcode: $('f_loc_postcode').value, port_of_loading: $('f_port_of_loading').value,
      target_other: $('f_target_other').value, enclosure_other: $('f_enclosure_other').value, remedial_action: $('f_remedial_action').value, suitable: radio('f_suitable'), heaters_used: radio('f_heaters_used'),
      min_enclosure_temp_c: $('f_min_enclosure_temp_c').value, target_of_fumigation: Array.prototype.map.call(document.querySelectorAll('.tg:checked'), function (c) { return c.value; })};
    ['probe_location', 'load_factor_max', 'load_factor_est', 'retention_pct', 'ct_product'].forEach(function (k) { var el = $('f_' + k); if (el) { f[k] = el.value; } });
    post({action: 'details', treatment_id: tid, fields: JSON.stringify(f)}).then(function () { say('Saved.'); load(tid); }).catch(function (e) { say(e.message, true); });
  }
  function addTemp() {
    var vals = {}; document.querySelectorAll('.t_v').forEach(function (i) { if (i.value.trim() !== '') { vals[i.dataset.n] = i.value; } });
    var at = $('t_at').value; if (!at) { say('Enter the date and time of the reading.', true); return; }
    post({action: 'temps', treatment_id: tid, values: JSON.stringify(vals), at: new Date(at).toISOString(), initials: $('t_ini').value}).then(function () { say('Row added.'); load(tid); }).catch(function (e) { say(e.message, true); });
  }
  var opts = null;
  function fillSel(id, rows, label, filter) { $(id).innerHTML = rows.filter(filter || function () { return true; }).map(function (r) { return '<option value="' + esc(r.id) + '">' + esc(r[label]) + '</option>'; }).join(''); }
  function fillSched() { fillSel('n_sched', opts.schedules, 'name', function (s) { return s.treatment_type === $('n_type').value; }); }
  fetch(API + '?options=1', {credentials: 'same-origin'}).then(function (r) { return r.ok ? r.json() : null; }).then(function (o) {
    if (!o) { return; }
    opts = o; canNew = true; $('newcard').hidden = !$('doc').hidden;
    fillSel('n_tech', o.technicians, 'full_name'); fillSel('n_off', o.officers, 'full_name'); fillSched(); $('n_type').onchange = fillSched;
    var miss = []; if (!o.technicians.length) { miss.push('a technician'); } if (!o.officers.length) { miss.push('an officer'); } if (!o.schedules.length) { miss.push('a treatment schedule'); }
    if (miss.length) { $('needs').textContent = 'Before you can start a record, add ' + miss.join(', ') + ' from the All Pest menu (Technicians, Officers, Schedules).'; $('newform').hidden = true; }
    $('n_go').onclick = function () {
      post({action: 'create', customer_name: $('n_name').value, customer_email: $('n_email').value, customer_address: $('n_addr').value, site_address: $('n_site').value, containers: $('n_cont').value,
        rof_type: $('n_type').value, schedule_id: $('n_sched').value, technician_id: $('n_tech').value, officer_id: $('n_off').value, enclosure_type: $('n_enc').value, fumigation_type: $('n_fum').value,
        scheduled_start: $('n_start').value ? new Date($('n_start').value).toISOString() : ''}).then(function (j) { say('Created ' + j.reference + ((j.treatment_ids || []).length > 1 ? ' with ' + j.treatment_ids.length + ' records, one per container' : '') + '.'); $('newcard').hidden = true; load(j.treatment_id); }).catch(function (e) { say(e.message, true); });
    };
  }).catch(function () {});
  $('b_back').onclick = function () { $('doc').hidden = true; $('bar').hidden = true; $('pick').hidden = false; $('newcard').hidden = !canNew; say(''); };
  var pre = window.AP_ROF_NEXT || null; window.AP_ROF_NEXT = null;
  if (pre) { load(pre); }
  fetch(TAPI + '?mine=1', {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (rows) {
    $('mine').innerHTML = rows.length ? rows.map(function (t) { return '<p><a href="#" data-id="' + esc(t.id) + '"><strong>' + esc(t.reference) + '</strong></a> ' + esc(t.customer_name) + ' &middot; ' + esc(t.rof_type) + ' &middot; ' + esc(t.status) + '</p>'; }).join('') : '<p class="ap-muted">No treatments yet.</p>';
    $('mine').onclick = function (ev) { var a = ev.target.closest('a[data-id]'); if (a) { ev.preventDefault(); load(a.dataset.id); } };
  }).catch(function () { say('Could not load treatments.', true); });
})();
</script>
