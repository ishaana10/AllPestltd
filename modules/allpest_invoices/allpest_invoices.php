<?php
declare(strict_types=1);
/**
 * Invoices, loaded inline in the StudioX shell (sidebar: All Pest > Invoices). Finance edits the draft raised when a request's last ROF
 * closes, verifies the charges, then clicks Post to send it to NuvisERPX. Everything goes through api/allpest/invoices.php.
 */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApAccess.php';
foreach (['ApDb', 'ApSchema'] as $c) { require_once dirname(__DIR__, 2) . "/core/allpest/$c.php"; }
$db = NuDatabase::getInstance();
ApSchema::ensure($db);
ApDb::set($db);
if (!ApAccess::can((string)($_SESSION['nu_user_id'] ?? ''), 'admin.invoices')) {
    http_response_code(403);
    exit('<div style="padding:24px">Access denied.</div>');
}
?>
<link rel="stylesheet" href="assets/css/allpest-studio.css">
<div class="ap-scope">
  <h1>Invoices</h1>
  <p id="msg" class="ap-muted"></p>
  <div class="ap-card" id="list">Loading…</div>
  <div class="ap-card" id="inv" hidden></div>
</div>
<script>
(function () {
  var api = 'api/allpest/invoices.php', msg = document.getElementById('msg'), list = document.getElementById('list'), box = document.getElementById('inv'), cur = null;
  function say(t, bad) { msg.textContent = t || ''; msg.style.color = bad ? '#b91c1c' : ''; }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function money(v) { return (Number(v) || 0).toFixed(2); }
  function call(data) {
    return fetch(api, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(data), credentials: 'same-origin'})
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) { throw new Error(j.error || 'Not saved'); } return j; }); });
  }
  var LABEL = {draft: 'Draft (finance to check)', verified: 'Verified - ready to post', posting: 'Posting to ERPX (will retry)', sending: 'Posting to ERPX', posted: 'Posted to ERPX'};
  function loadList() {
    fetch(api, {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (rows) {
      if (!rows.length) { list.innerHTML = '<p class="ap-muted">No invoices yet. One is raised when every ROF of a request is closed.</p>'; return; }
      list.innerHTML = '<table class="ap-table"><tr><th>Invoice</th><th>Request</th><th>Customer</th><th>Total</th><th>Status</th><th>ERPX</th></tr>' + rows.map(function (r) {
        return '<tr class="row" data-id="' + esc(r.id) + '" style="cursor:pointer"><td>' + esc(r.invoice_no) + '</td><td>' + esc(r.reference) + '</td><td>' + esc(r.customer_name) + '</td><td>' + money(r.total) + '</td><td>' + esc(LABEL[r.status] || r.status) + '</td><td>' + esc(r.erpx_invoice_no || '') + '</td></tr>';
      }).join('') + '</table>';
    }).catch(function () { say('Could not load invoices.', true); });
  }
  function show(inv) {
    cur = inv;
    var edit = inv.status === 'draft';
    var rows = inv.lines.map(function (l) {
      return '<tr data-line="' + esc(l.id) + '"><td>' + (edit ? '<input class="d" style="width:100%" value="' + esc(l.description) + '">' : esc(l.description)) + '</td><td>' +
        (edit ? '<input class="q" style="width:4em" inputmode="decimal" value="' + esc(l.qty) + '">' : esc(l.qty)) + '</td><td>' +
        (edit ? '<input class="u" style="width:7em" inputmode="decimal" value="' + esc(money(l.unit_price)) + '">' : money(l.unit_price)) + '</td><td>' + money(l.tax_amount) + '</td><td>' + money(l.amount) + '</td></tr>';
    }).join('');
    var err = inv.last_error ? '<p style="color:#b91c1c">ERPX said: ' + esc(inv.last_error) + ' (attempt ' + esc(inv.attempts) + '; it is retried automatically)</p>' : '';
    box.hidden = false;
    box.innerHTML = '<p><a href="#" id="back">&larr; All invoices</a></p><h2>' + esc(inv.invoice_no) + ' <span class="ap-badge">' + esc(LABEL[inv.status] || inv.status) + '</span></h2>' +
      '<p>' + esc(inv.customer_name) + (inv.customer_tin ? ' &middot; TIN ' + esc(inv.customer_tin) : '') + '<br>Request ' + esc(inv.reference) + ' &middot; Invoice date ' + esc(inv.invoice_date) + ' &middot; Due ' +
      (edit ? '<input id="due" type="date" value="' + esc(inv.due_date) + '">' : esc(inv.due_date)) + '</p>' + err +
      '<table class="ap-table"><tr><th>Description</th><th>Qty</th><th>Unit price</th><th>Tax (' + esc(money(inv.tax_rate)) + '%)</th><th>Amount</th></tr>' + rows + '</table>' +
      '<p>Subtotal <strong>' + money(inv.subtotal) + '</strong> &middot; Discount ' + (edit ? '<input id="disc" style="width:6em" inputmode="decimal" value="' + esc(money(inv.discount)) + '">' : money(inv.discount)) +
      ' &middot; Tax <strong>' + money(inv.tax_amount) + '</strong> &middot; Total <strong>' + money(inv.total) + '</strong></p>' +
      '<label class="ap-field"><span>Notes</span>' + (edit ? '<input id="notes" value="' + esc(inv.notes) + '">' : '<span>' + esc(inv.notes) + '</span>') + '</label>' +
      (inv.status === 'verified' ? '<p class="ap-muted">Verified by finance on ' + esc(inv.verified_at) + '.</p>' : '') +
      (inv.status === 'posted' ? '<p>ERPX invoice <strong>' + esc(inv.erpx_invoice_no) + '</strong> &middot; posted ' + esc(inv.posted_at) + '</p>' : '') +
      '<p id="inline" class="ap-muted"></p>' +
      (edit ? '<button class="ap-btn alt" id="save">Save changes</button> <button class="ap-btn" id="verify">Verify charges</button>' : '') +
      (inv.status === 'verified' ? '<button class="ap-btn" id="post">Post to ERPX</button> <button class="ap-btn alt" id="reopen">Reopen for changes</button>' : '') +
      (inv.status === 'posting' ? '<p class="ap-muted">Waiting for ERPX. It is retried automatically; nothing more to do.</p>' : '');
  }
  function note(t, bad) { var n = document.getElementById('inline'); if (n) { n.textContent = t; n.style.color = bad ? '#b91c1c' : ''; } say(t, bad); }
  function collect() {
    var lines = [].map.call(box.querySelectorAll('tr[data-line]'), function (tr) {
      return {id: tr.dataset.line, description: tr.querySelector('.d').value, qty: tr.querySelector('.q').value, unit_price: tr.querySelector('.u').value};
    });
    return {id: cur.id, lines: lines, discount: document.getElementById('disc').value || '0', notes: document.getElementById('notes').value, due_date: document.getElementById('due').value};
  }
  function act(data, ok) { call(data).then(function (inv) { show(inv); note(ok); loadList(); }).catch(function (e) { note(e.message, true); }); }
  box.addEventListener('click', function (ev) {
    var id = ev.target.id;
    if (id === 'back') { ev.preventDefault(); box.hidden = true; say(''); loadList(); }
    else if (id === 'save') { act(Object.assign({action: 'update'}, collect()), 'Saved.'); }
    else if (id === 'verify') { call(Object.assign({action: 'update'}, collect())).then(function () { return call({action: 'verify', id: cur.id}); }).then(function (inv) { show(inv); note('Charges verified. Click Post to ERPX when ready.'); loadList(); }).catch(function (e) { note(e.message, true); }); }
    else if (id === 'post') { act({action: 'post', id: cur.id}, 'Sent. If ERPX is unreachable it is retried automatically.'); }
    else if (id === 'reopen') { act({action: 'reopen', id: cur.id}, 'Reopened for changes.'); }
  });
  list.addEventListener('click', function (ev) {
    var tr = ev.target.closest('tr.row'); if (!tr) { return; }
    fetch(api + '?id=' + encodeURIComponent(tr.dataset.id), {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(show).catch(function () { say('Could not open the invoice.', true); });
  });
  loadList();
})();
</script>
