<?php
declare(strict_types=1);
/**
 * All Pest dashboard and reports, loaded inline in the StudioX shell (sidebar: All Pest > Dashboard and Reports).
 * KPI tiles and SVG charts (no chart library) from api/allpest/reports.php, plus report tables with CSV download.
 */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApAccess.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApDb.php';
require_once dirname(__DIR__, 2) . '/core/allpest/ApSchema.php';
$db = NuDatabase::getInstance();
ApSchema::ensure($db);
ApDb::set($db);
if (!ApAccess::can((string)($_SESSION['nu_user_id'] ?? ''), 'admin.reports')) {
    http_response_code(403);
    exit('<div style="padding:24px">Access denied.</div>');
}
?>
<link rel="stylesheet" href="assets/css/allpest-studio.css">
<style>
  .ap-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:12px 0}
  .ap-kpi{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px}
  .ap-kpi b{display:block;font-size:1.5rem;line-height:1.2} .ap-kpi span{color:#64748b;font-size:.85rem}
  .ap-kpi.bad b{color:#b91c1c} .ap-kpi.warn b{color:#b45309}
  .ap-charts{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:12px}
  .ap-tabs button{margin:0 6px 6px 0}.ap-tabs button.on{background:#1e293b;color:#fff}
  .ap-rep{overflow:auto;max-height:460px}
  .ap-legend span{display:inline-block;margin-right:12px;font-size:.85rem}.ap-legend i{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:4px}
</style>
<div class="ap-scope">
  <h1>Dashboard and Reports</h1>
  <p class="ap-muted">Period (Fiji dates): <input type="date" id="from"> to <input type="date" id="to"> <button class="ap-btn" id="go">Show</button> <span id="msg"></span></p>
  <div class="ap-kpis" id="kpis"></div>
  <div class="ap-charts">
    <div class="ap-card"><h2>Containers treated per month</h2><div id="c1"></div><div class="ap-legend"><span><i style="background:#16a34a"></i>Pass</span><span><i style="background:#dc2626"></i>Fail</span></div></div>
    <div class="ap-card"><h2>Methyl bromide used per month (kg)</h2><div id="c2"></div></div>
    <div class="ap-card"><h2>Invoiced value by status</h2><div id="c3"></div></div>
    <div class="ap-card"><h2>Gas stock by lot (kg)</h2><div id="c4"></div></div>
  </div>
  <div class="ap-card">
    <div class="ap-tabs" id="tabs"></div>
    <p><a class="ap-btn alt" id="csv" href="#">Download CSV</a> <span id="note" class="ap-muted"></span></p>
    <div class="ap-rep" id="rep">Loading…</div>
  </div>
</div>
<script>
(function () {
  var api = 'api/allpest/reports.php', $ = function (i) { return document.getElementById(i); }, tab = 'fumigations';
  var TABS = [['fumigations', 'Fumigations'], ['inventory', 'Inventory'], ['movements', 'Stock ledger'], ['invoices', 'Invoices'], ['certificates', 'Certificates'], ['technicians', 'Technicians'], ['audit', 'Audit trail']];
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function qs(extra) { return '?from=' + $('from').value + '&to=' + $('to').value + (extra || ''); }
  function get(report) { return fetch(api + qs('&report=' + report), {credentials: 'same-origin'}).then(function (r) { return r.json().then(function (j) { if (!r.ok) { throw new Error(j.error || 'Failed'); } return j; }); }); }
  function num(v, d) { return (Number(v) || 0).toLocaleString(undefined, {minimumFractionDigits: d || 0, maximumFractionDigits: d || 0}); }

  function bars(el, labels, series, colors, unit) {
    if (!labels.length) { el.innerHTML = '<p class="ap-muted">No data in this period.</p>'; return; }
    var W = 320, H = 170, L = 34, B = 22, T = 8, max = 0, n = labels.length, bw = (W - L - 6) / n;
    labels.forEach(function (_, i) { var s = 0; series.forEach(function (S) { s += Number(S[i]) || 0; }); if (s > max) { max = s; } });
    if (max <= 0) { max = 1; }
    var g = '<line x1="' + L + '" y1="' + (H - B) + '" x2="' + W + '" y2="' + (H - B) + '" stroke="#cbd5e1"/><text x="2" y="' + (T + 8) + '" font-size="9" fill="#64748b">' + esc(num(max, max < 10 ? 1 : 0)) + '</text>';
    labels.forEach(function (lab, i) {
      var y = H - B, x = L + i * bw + bw * 0.15, w = bw * 0.7;
      series.forEach(function (S, k) { var v = Number(S[i]) || 0, h = v / max * (H - B - T); y -= h; if (h > 0) { g += '<rect x="' + x + '" y="' + y + '" width="' + w + '" height="' + h + '" fill="' + colors[k] + '"><title>' + esc(lab + ': ' + num(v, v % 1 ? 1 : 0) + (unit || '')) + '</title></rect>'; } });
      g += '<text x="' + (x + w / 2) + '" y="' + (H - 8) + '" font-size="9" text-anchor="middle" fill="#64748b">' + esc(String(lab).slice(-5)) + '</text>';
    });
    el.innerHTML = '<svg viewBox="0 0 ' + W + ' ' + H + '" width="100%" role="img">' + g + '</svg>';
  }
  function kpi(label, val, cls) { return '<div class="ap-kpi ' + (cls || '') + '"><b>' + esc(val) + '</b><span>' + esc(label) + '</span></div>'; }
  function table(rows, cols) {
    if (!rows.length) { return '<p class="ap-muted">Nothing to show for this period.</p>'; }
    cols = cols || Object.keys(rows[0]);
    return '<table class="ap-table"><tr>' + cols.map(function (c) { return '<th>' + esc(c.replace(/_/g, ' ')) + '</th>'; }).join('') + '</tr>' +
      rows.map(function (r) { return '<tr>' + cols.map(function (c) { return '<td>' + esc(r[c]) + '</td>'; }).join('') + '</tr>'; }).join('') + '</table>';
  }
  function loadTop() {
    Promise.all([get('summary'), get('monthly'), get('invoices'), get('inventory')]).then(function (a) {
      var s = a[0], m = a[1], inv = a[2], st = a[3];
      $('kpis').innerHTML = kpi('Requests received', num(s.requests)) + kpi('ROFs closed', num(s.rofs_closed)) + kpi('ROFs still open', num(s.rofs_open), s.rofs_open ? 'warn' : '') +
        kpi('Containers treated', num(s.containers)) + kpi('Pass rate', s.containers ? s.pass_rate + '%' : 'n/a', s.fail ? 'warn' : '') + kpi('Failed containers', num(s.fail), s.fail ? 'bad' : '') +
        kpi('Methyl bromide used', num(s.mb_used_g / 1000, 1) + ' kg') + kpi('Gas in stock', num(st.total_balance_g / 1000, 1) + ' kg', st.lots.some(function (l) { return l.low; }) ? 'bad' : '') +
        kpi('Certificates issued', num(s.certificates_issued)) + kpi('Certificate emails pending', num(s.certificate_emails_pending), s.certificate_emails_pending ? 'warn' : '') +
        kpi('Invoiced and posted', num(s.invoiced_posted, 2)) + kpi('Invoiced, not yet posted', num(inv.unposted_total, 2), inv.unposted_over_7_days ? 'warn' : '');
      var lab = m.map(function (r) { return r.month; });
      bars($('c1'), lab, [m.map(function (r) { return r.pass; }), m.map(function (r) { return r.fail; })], ['#16a34a', '#dc2626'], ' containers');
      bars($('c2'), lab, [m.map(function (r) { return r.mb_g / 1000; })], ['#2563eb'], ' kg');
      var names = Object.keys(inv.by_status || {});
      bars($('c3'), names, [names.map(function (k) { return inv.by_status[k].total; })], ['#7c3aed'], '');
      bars($('c4'), st.lots.map(function (l) { return l.lot_ref; }), [st.lots.map(function (l) { return l.balance / 1000; })], ['#0891b2'], ' kg');
    }).catch(function (e) { $('msg').textContent = e.message; $('msg').style.color = '#b91c1c'; });
  }
  function loadTab() {
    $('tabs').innerHTML = TABS.map(function (t) { return '<button class="ap-btn alt' + (t[0] === tab ? ' on' : '') + '" data-t="' + t[0] + '">' + t[1] + '</button>'; }).join('');
    $('csv').href = api + qs('&report=' + tab + '&format=csv');
    $('note').textContent = '';
    get(tab).then(function (d) {
      var html;
      if (tab === 'inventory') { html = table(d.lots.map(function (l) { return {lot: l.lot_ref, balance_g: l.balance, received_g: l.received_g, consumed_g: l.consumed_g, adjusted_g: l.adjusted_g, discrepancies: l.discrepancies, status: l.low ? 'LOW STOCK' : 'ok'}; })); $('note').textContent = 'Low stock threshold ' + num(d.threshold_g) + ' g. Used in this period: ' + num(d.consumed_in_range_g) + ' g.'; }
      else if (tab === 'invoices') { html = table(d.rows); $('note').textContent = d.unposted_over_7_days + ' invoice(s) not posted for over 7 days.'; }
      else if (tab === 'audit') { html = table(d.rows); $('note').textContent = d.chain && d.chain.ok ? 'Audit chain verified (' + d.chain.checked + ' entries).' : 'AUDIT CHAIN PROBLEM: ' + (d.chain ? d.chain.reason : 'unknown'); }
      else { html = table(d); }
      $('rep').innerHTML = html;
    }).catch(function (e) { $('rep').innerHTML = '<p style="color:#b91c1c">' + esc(e.message) + '</p>'; });
  }
  $('tabs').addEventListener('click', function (ev) { var t = ev.target.dataset && ev.target.dataset.t; if (t) { tab = t; loadTab(); } });
  $('go').onclick = function () { loadTop(); loadTab(); };
  var today = new Date(), start = new Date(today.getTime() - 90 * 864e5);
  $('from').value = iso(start); $('to').value = iso(today);
  loadTop(); loadTab();
})();
</script>
