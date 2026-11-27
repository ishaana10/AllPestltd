/* The technician's record of fumigation. One screen, sections open as the treatment progresses. */
(function () {
  'use strict';
  var U = ApUi, id = U.qs('id'), T = null, EQ = [], LOTS = [], $ = function (x) { return document.getElementById(x); };
  var tcName = function (c) { return c.container_no || c.id.slice(0, 8); };

  if (!id) {
    $('pick').hidden = false;
    U.get('treatments.php', { mine: 1 }).then(function (r) { U.table($('mine'), r, [{ label: 'Reference', render: function (x) { return '<a href="?id=' + U.esc(x.id) + '">' + U.esc(x.reference) + '</a>'; } }, { label: 'Customer', key: 'customer_name' }, { label: 'Status', key: 'status' }]); }, U.fail);
    return;
  }
  $('rof').hidden = false;
  ApQueue.onChange(function (n) { $('queue').textContent = n ? n + ' reading set(s) saved on this device, waiting to send.' : ''; });

  function reload() {
    return Promise.all([U.get('treatments.php', { id: id, live: 1 }), U.get('equipment.php'), U.get('treatments.php', { lots: 1 })]).then(function (a) { T = a[0]; EQ = a[1]; LOTS = a[2]; render(); }, U.fail);
  }
  function post(obj) { obj.treatment_id = id; return U.post('treatments.php', obj); }
  function show(sec, on) { $(sec).hidden = !on; }

  function render() {
    var st = T.status;
    $('head').innerHTML = '<strong>' + U.esc(T.rof_type) + '</strong> fumigation &middot; <span class="ap-badge">' + U.esc(st) + '</span> &middot; version ' + U.esc(T.version) +
      '<br>' + T.containers.map(function (c) { return U.esc(tcName(c)); }).join(', ') + (st === 'closed' ? '<br><a href="signing.php?id=' + U.esc(id) + '">Go to signing and certificates</a>' : '');
    var locked = !!T.locked_at;
    $('locked').hidden = !locked; if (locked) { $('locked').textContent = 'This record is signed and locked. Use the correction form on the signing page.'; }
    show('s_pre', !locked && (st === 'planned' || st === 'prechecked'));
    show('s_dose', !locked && st === 'prechecked');
    show('s_read', !locked && (st === 'dosed' || st === 'exposure'));
    show('s_end', !locked && (st === 'exposure' || st === 'ventilating'));
    if (!$('s_pre').hidden) { renderPre(); }
    if (!$('s_dose').hidden) { renderDose(); }
    if (!$('s_read').hidden) { renderRead(); }
    if (!$('s_end').hidden) { renderEnd(); }
  }

  function renderPre() {
    var eq = EQ.map(function (e) { return '<label style="display:block"><input type="checkbox" class="eq" value="' + U.esc(e.id) + '" style="width:auto;min-height:0"> ' + U.esc(e.kind) + ' - ' + U.esc(e.name) + '</label>'; }).join('');
    $('pre').innerHTML = '<div class="ap-grid"><label class="ap-field"><span>Height (m)</span><input id="h" type="number" step="any" inputmode="decimal"></label><label class="ap-field"><span>Depth (m)</span><input id="d" type="number" step="any" inputmode="decimal"></label><label class="ap-field"><span>Length (m)</span><input id="l" type="number" step="any" inputmode="decimal"></label><label class="ap-field"><span>Increment (g)</span><input id="inc" type="number" step="any" value="1" inputmode="decimal"></label></div>' +
      '<label><input type="checkbox" id="chl" style="width:auto;min-height:0"> Chloropicrin added</label><br><label><input type="checkbox" id="wg" style="width:auto;min-height:0"> Round to whole grams</label>' +
      '<h2>Equipment used</h2>' + eq + '<h2>Forecast</h2><label><input type="radio" name="fc" value="auto" checked style="width:auto;min-height:0"> Fetch the forecast automatically</label><br><label><input type="radio" name="fc" value="manual" style="width:auto;min-height:0"> Enter it myself</label>' +
      '<div id="man" hidden><label class="ap-field"><span>Lowest forecast temperature (C)</span><input id="mt" type="number" step="any"></label><label class="ap-field"><span>Where it came from</span><input id="mn"></label></div>' +
      '<button class="ap-btn" id="go">Run the check</button><div id="pr"></div>';
    document.querySelectorAll('input[name=fc]').forEach(function (r) { r.onchange = function () { $('man').hidden = this.value !== 'manual' || !this.checked; }; });
    $('go').onclick = function () {
      var manual = document.querySelector('input[name=fc]:checked').value === 'manual';
      var input = { height_m: $('h').value, depth_m: $('d').value, length_m: $('l').value, increment: $('inc').value, chloropicrin: $('chl').checked, whole_gram: $('wg').checked,
        equipment_ids: [].map.call(document.querySelectorAll('.eq:checked'), function (x) { return x.value; }),
        forecast: manual ? { manual_min_c: parseFloat($('mt').value), source_note: $('mn').value } : 'auto' };
      post({ action: 'precheck', input: JSON.stringify(input) }).then(function (r) {
        var blocked = r.blocked && r.blocked.length;
        $('pr').innerHTML = '<p>Volume ' + U.esc(r.volume_m3) + ' m3 &middot; dose rate ' + U.esc(r.dose_rate_used) + ' g/m3 &middot; <strong>dose ' + U.esc(r.rounded_dose_g) + ' g</strong> &middot; forecast low ' + U.esc(r.forecast_min_c) + ' C</p>' +
          (blocked ? r.blocked.map(function (b) { return '<div class="ap-alert">' + U.esc(b) + '</div>'; }).join('') : '<p>Ready to dose.</p>');
        if (!blocked) { reload(); }
      }, U.fail);
    };
  }

  function renderDose() {
    var perish = T.rof_type === 'perishable';
    var core = perish ? '<h2>Core temperatures (C)</h2>' + T.containers.map(function (c) { return '<label class="ap-field"><span>' + U.esc(tcName(c)) + '</span><input class="core" data-id="' + U.esc(c.id) + '" type="number" step="any" value="' + U.esc(c.core_temp_c == null ? '' : c.core_temp_c) + '"></label>'; }).join('') + '<button class="ap-btn alt" id="coresave">Save core temperatures</button>' : '';
    $('dose').innerHTML = core + '<label class="ap-field"><span>Gas lot</span><select id="lot">' + LOTS.map(function (l) { return '<option value="' + U.esc(l.id) + '">' + U.esc(l.lot_ref) + ' (' + U.esc(l.balance) + ' g)</option>'; }).join('') + '</select></label>' +
      '<label class="ap-field"><span>Grams applied</span><input id="g" type="number" step="any" inputmode="decimal" value="' + U.esc(T.dose_g || '') + '"></label><button class="ap-btn" id="inject">Gas applied now</button>';
    if ($('coresave')) { $('coresave').onclick = function () { var p = Promise.resolve(); document.querySelectorAll('.core').forEach(function (i) { if (i.value !== '') { p = p.then(function () { return post({ action: 'core_temp', container_id: i.dataset.id, temp_c: i.value }); }); } }); p.then(function () { U.toast('Saved'); }, U.fail); }; }
    $('inject').onclick = function () { post({ action: 'dose', injected_at: U.nowIso(), grams: $('g').value, lot_id: $('lot').value }).then(function () { U.toast('Dosing recorded'); reload(); }, U.fail); };
  }

  function renderRead() {
    var L = T.live, n = L.required_locations, rows = '';
    T.containers.forEach(function (c) {
      rows += '<h2>' + U.esc(tcName(c)) + '</h2><div class="ap-grid">';
      for (var i = 1; i <= n; i++) { rows += '<label class="ap-field"><span>Location ' + i + '</span><input class="ap-reading rd" data-c="' + U.esc(c.id) + '" data-l="L' + i + '" inputmode="decimal" autocomplete="off"></label>'; }
      rows += '</div>';
    });
    var stages = L.stage === 'start_point' ? [['start_point', 'Start point']] : [['exposure', 'During exposure'], ['end_exposure', 'End of exposure']];
    $('read').innerHTML = '<p>Standard now: <strong id="std">' + (L.standard == null ? 'n/a' : U.esc(L.standard.toFixed(1))) + ' g/m3</strong> <span class="ap-muted">(minimum ' + n + ' locations per container)</span></p>' +
      '<label class="ap-field"><span>Reading round</span><select id="stage">' + stages.map(function (s) { return '<option value="' + s[0] + '">' + s[1] + '</option>'; }).join('') + '</select></label>' + rows +
      '<div id="alert"></div><label class="ap-field"><span>Photo of the instrument (required)</span><input id="photo" type="file" accept="image/*" capture="environment"></label>' +
      '<label class="ap-field"><span>Your initials</span><input id="ini" maxlength="10"></label><button class="ap-btn" id="saveset">Save readings</button><div id="verdict"></div>';
    function check() {
      var low = 0; document.querySelectorAll('.rd').forEach(function (i) {
        var v = parseFloat(String(i.value).replace(',', '.')); i.classList.remove('low', 'ok');
        if (isFinite(v) && L.standard != null) { if (v < L.standard) { i.classList.add('low'); low++; } else { i.classList.add('ok'); } }
      });
      var tu = (low && L.topups_allowed && L.topup_min != null) ? ' A top-up is possible only if readings are at least ' + L.topup_min + ' g/m3; the exact amount is shown after saving.' : (low && !L.topups_allowed ? ' Top-ups are not allowed for this schedule.' : '');
      $('alert').innerHTML = low ? '<div class="ap-alert">' + low + ' reading(s) below the standard of ' + L.standard.toFixed(1) + ' g/m3.' + U.esc(tu) + '</div>' : '';
    }
    document.querySelectorAll('.rd').forEach(function (i) { i.oninput = check; });
    $('saveset').onclick = function () {
      var values = {}, empty = false;
      document.querySelectorAll('.rd').forEach(function (i) { if (i.value.trim() === '') { empty = true; } (values[i.dataset.c] = values[i.dataset.c] || {})[i.dataset.l] = i.value.trim(); });
      if (empty) { U.toast('Fill in every reading', true); return; }
      var f = $('photo').files[0]; if (!f) { U.toast('Take a photo of the instrument', true); return; }
      if (!$('ini').value.trim()) { U.toast('Enter your initials', true); return; }
      var btn = $('saveset'); btn.disabled = true;
      ApCompress.image(f).then(ApCompress.toDataUrl).then(function (d) {
        var item = { client_uuid: ApQueue.uuid(), treatment_id: id, stage: $('stage').value, at: U.nowIso(), values: values, initials: $('ini').value.trim(), photos: [d], file_ids: [] };
        ApQueue.enqueue(item);
        return ApQueue.flush(function (it, res, err) {
          if (err) { U.toast(err.message, true); return; }
          var v = res; $('verdict').innerHTML = '<div class="ap-card"><p>Standard ' + U.esc(v.standard) + ' g/m3 &middot; ' + (v.allMeet ? 'all locations meet it' : '<strong>some locations are below it</strong>') + '</p>' +
            (v.topUp && v.topUp.possible ? '<div class="ap-alert">Top-up of ' + U.esc(v.topUp.grams) + ' g can rescue this.</div>' : '') +
            (v.startPointReached ? '<p>Start point reached. Exposure has begun.</p>' : '') + (v.additionalDoseG ? '<p>Add ' + U.esc(v.additionalDoseG) + ' g to reach the start-point standard.</p>' : '') + '</div>';
          reload();
        });
      }).then(function () { btn.disabled = false; if (ApQueue.size()) { U.toast('Saved on this device. It will send when you are online.'); } }, function (e) { btn.disabled = false; U.fail(e); });
    };
  }

  function renderEnd() {
    var st = T.status, h = '';
    if (st === 'exposure') {
      h += '<h2>Top-up (only if a reading round said it is possible)</h2><label class="ap-field"><span>Lot</span><select id="tlot">' + LOTS.map(function (l) { return '<option value="' + U.esc(l.id) + '">' + U.esc(l.lot_ref) + '</option>'; }).join('') + '</select></label><label class="ap-field"><span>Grams added</span><input id="tg" type="number" step="any"></label><button class="ap-btn alt" id="topup">Record top-up</button>' +
        '<h2>Ventilation</h2><button class="ap-btn" id="vent">Ventilation started now</button>';
    } else {
      h += '<h2>TLV reading after ventilation (ppm, must be 5 or less)</h2>' + T.containers.map(function (c) { return '<label class="ap-field"><span>' + U.esc(tcName(c)) + (c.tlv_ppm != null ? ' (saved: ' + U.esc(c.tlv_ppm) + ')' : '') + '</span><input class="tlv" data-id="' + U.esc(c.id) + '" type="number" step="any"></label>'; }).join('') + '<button class="ap-btn alt" id="tlvsave">Save TLV readings</button><button class="ap-btn" id="close">Close treatment</button>';
    }
    $('end').innerHTML = h;
    if ($('topup')) { $('topup').onclick = function () { post({ action: 'topup', at: U.nowIso(), grams: $('tg').value, lot_id: $('tlot').value }).then(function () { U.toast('Top-up recorded'); reload(); }, U.fail); }; }
    if ($('vent')) { $('vent').onclick = function () { post({ action: 'ventilation', at: U.nowIso() }).then(function () { reload(); }, U.fail); }; }
    if ($('tlvsave')) { $('tlvsave').onclick = function () { var p = Promise.resolve(); document.querySelectorAll('.tlv').forEach(function (i) { if (i.value !== '') { p = p.then(function () { return post({ action: 'tlv', container_id: i.dataset.id, ppm: i.value, at: U.nowIso() }); }); } }); p.then(function () { U.toast('Saved'); reload(); }, U.fail); }; }
    if ($('close')) { $('close').onclick = function () { post({ action: 'close' }).then(function (r) { U.toast('Closed: ' + r.overall); reload(); }, U.fail); }; }
  }
  reload();
})();
