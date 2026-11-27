<?php
declare(strict_types=1);
/** Methyl bromide stock, loaded inline in the StudioX shell (sidebar: All Pest > Stock). Lots with balances, receive and stocktake actions via api/allpest/stock.php. */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApAccess.php';
if (!ApAccess::can((string)($_SESSION['nu_user_id'] ?? ''), 'admin.stock')) {
    http_response_code(403);
    exit('<div style="padding:24px">Access denied.</div>');
}
?>
<link rel="stylesheet" href="assets/css/allpest-studio.css">
<div class="ap-scope">
  <h1>Stock</h1>
  <p id="msg" class="ap-muted"></p>
  <div class="ap-card"><h2>Lots</h2><div id="lots">Loading…</div></div>
  <div class="ap-card"><h2>New lot</h2>
    <label class="ap-field"><span>Lot reference</span><input id="n_ref"></label>
    <label class="ap-field"><span>Description</span><input id="n_desc"></label>
    <label class="ap-field"><span>Methyl bromide fraction (0 to 1)</span><input id="n_frac" value="1" inputmode="decimal"></label>
    <button class="ap-btn" id="n_go">Create lot</button></div>
</div>
<script>
(function () {
  var api = 'api/allpest/stock.php', msg = document.getElementById('msg'), box = document.getElementById('lots');
  function say(t, bad) { msg.textContent = t; msg.style.color = bad ? '#b91c1c' : ''; }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function post(data) {
    return fetch(api, {method: 'POST', body: new URLSearchParams(data), credentials: 'same-origin'}).then(function (r) { return r.json().then(function (j) { if (!r.ok) { throw new Error(j.error || 'Not saved'); } return j; }); });
  }
  function load() {
    fetch(api, {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (lots) {
      if (!lots.length) { box.innerHTML = '<p class="ap-muted">No lots yet. Create one below.</p>'; return; }
      box.innerHTML = lots.map(function (l) {
        return '<div class="ap-card" data-id="' + esc(l.id) + '"><strong>' + esc(l.lot_ref) + '</strong> ' + esc(l.description) + ' &middot; balance <strong>' + esc(l.balance) + ' g</strong><br>' +
          '<input class="g" placeholder="grams" inputmode="decimal" style="width:7em"> <button class="ap-btn rec">Receive</button> ' +
          '<input class="a" placeholder="+/- grams" inputmode="decimal" style="width:7em"> <input class="r" placeholder="reason (stocktake)"> <button class="ap-btn adj">Adjust</button></div>';
      }).join('');
    }).catch(function () { say('Could not load stock.', true); });
  }
  box.addEventListener('click', function (ev) {
    var card = ev.target.closest('[data-id]'); if (!card) { return; }
    var id = card.dataset.id;
    if (ev.target.classList.contains('rec')) { post({action: 'receive', lot_id: id, grams: card.querySelector('.g').value}).then(function () { say('Received.'); load(); }).catch(function (e) { say(e.message, true); }); }
    if (ev.target.classList.contains('adj')) { post({action: 'adjust', lot_id: id, grams: card.querySelector('.a').value, reason: card.querySelector('.r').value}).then(function () { say('Adjusted.'); load(); }).catch(function (e) { say(e.message, true); }); }
  });
  document.getElementById('n_go').addEventListener('click', function () {
    post({action: 'create_lot', lot_ref: document.getElementById('n_ref').value, description: document.getElementById('n_desc').value, mb_fraction: document.getElementById('n_frac').value})
      .then(function () { say('Lot created.'); load(); }).catch(function (e) { say(e.message, true); });
  });
  load();
})();
</script>
