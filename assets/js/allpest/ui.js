/* Small helpers shared by the All Pest screens. No framework; every value is escaped before it reaches the DOM. */
window.ApUi = (function () {
  'use strict';
  var API = window.AP_API || '../../api/allpest/';
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function parse(r) { return r.text().then(function (t) { var j = null; try { j = JSON.parse(t); } catch (e) {} if (!r.ok) { throw new Error((j && j.error) || ('Request failed (' + r.status + ')')); } return j; }); }
  function get(file, params) { var q = params ? '?' + new URLSearchParams(params).toString() : ''; return fetch(API + file + q, { credentials: 'same-origin' }).then(parse); }
  function post(file, obj) {
    var f = new FormData();
    Object.keys(obj || {}).forEach(function (k) { var v = obj[k]; if (Array.isArray(v)) { v.forEach(function (x) { f.append(k + '[]', x); }); } else if (v !== undefined && v !== null) { f.append(k, v); } });
    return fetch(API + file, { method: 'POST', body: f, credentials: 'same-origin' }).then(parse);
  }
  function toast(msg, bad) { var t = document.getElementById('apToast'); if (!t) { t = document.createElement('div'); t.id = 'apToast'; t.setAttribute('role', 'status'); document.body.appendChild(t); } t.textContent = msg; t.className = 'ap-toast show' + (bad ? ' bad' : ''); clearTimeout(toast._t); toast._t = setTimeout(function () { t.className = 'ap-toast'; }, bad ? 7000 : 3000); }
  function fail(e) { toast(e && e.message ? e.message : String(e), true); }
  function table(el, rows, cols) {
    if (!rows || !rows.length) { el.innerHTML = '<p class="ap-muted">Nothing here yet.</p>'; return; }
    el.innerHTML = '<table class="ap-table"><thead><tr>' + cols.map(function (c) { return '<th>' + esc(c.label) + '</th>'; }).join('') + '</tr></thead><tbody>' +
      rows.map(function (r) { return '<tr>' + cols.map(function (c) { var v = c.render ? c.render(r) : esc(r[c.key]); return '<td>' + v + '</td>'; }).join('') + '</tr>'; }).join('') + '</tbody></table>';
  }
  /** fields: [{name,label,type?,options?,required?,placeholder?}]; onSubmit(values) returns a promise. */
  function form(el, fields, submitLabel, onSubmit) {
    el.innerHTML = fields.map(function (f) {
      var id = 'f_' + f.name, inp;
      if (f.type === 'select') { inp = '<select id="' + id + '">' + f.options.map(function (o) { return '<option value="' + esc(o.value) + '">' + esc(o.label) + '</option>'; }).join('') + '</select>'; }
      else if (f.type === 'textarea') { inp = '<textarea id="' + id + '" rows="3"></textarea>'; }
      else { inp = '<input id="' + id + '" type="' + (f.type || 'text') + '"' + (f.step ? ' step="' + f.step + '"' : '') + (f.placeholder ? ' placeholder="' + esc(f.placeholder) + '"' : '') + (f.value != null ? ' value="' + esc(f.value) + '"' : '') + '>'; }
      return '<label class="ap-field"><span>' + esc(f.label) + (f.required ? ' *' : '') + '</span>' + inp + '</label>';
    }).join('') + '<button type="button" class="ap-btn">' + esc(submitLabel) + '</button>';
    el.querySelector('button').addEventListener('click', function () {
      var v = {};
      for (var i = 0; i < fields.length; i++) {
        var f = fields[i], x = document.getElementById('f_' + f.name).value.trim();
        if (f.required && x === '') { toast(f.label + ' is required', true); return; }
        v[f.name] = x;
      }
      onSubmit(v).then(function () { fields.forEach(function (f) { if (f.type !== 'select') document.getElementById('f_' + f.name).value = ''; }); }, fail);
    });
  }
  function nowIso() { var d = new Date(), p = function (n) { return ('0' + n).slice(-2); }, o = -d.getTimezoneOffset(), a = Math.abs(o); return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds()) + (o >= 0 ? '+' : '-') + p(Math.floor(a / 60)) + ':' + p(a % 60); }
  function fiji(utc) { if (!utc) return ''; var d = new Date(String(utc).replace(' ', 'T') + 'Z'); if (isNaN(d)) return esc(utc); return d.toLocaleString('en-GB', { timeZone: 'Pacific/Fiji', dateStyle: 'medium', timeStyle: 'short' }); }
  function qs(k) { if (window.AP_PARAMS && k in window.AP_PARAMS) { return window.AP_PARAMS[k]; } return window.AP_PARAMS ? null : new URLSearchParams(location.search).get(k); }
  /** Open a treatment: inside the studio shell, reload the workspace module for it; as a standalone page, follow the link. */
  function open(id, page) {
    if (window.AP_PARAMS && window.NuApp && NuApp.loadModule) { window.AP_NEXT = id || null; NuApp.loadModule('allpest_work'); return; }
    location.href = (page || 'treatment.php') + (id ? '?id=' + encodeURIComponent(id) : '');
  }
  return { esc: esc, get: get, post: post, toast: toast, fail: fail, table: table, form: form, nowIso: nowIso, fiji: fiji, qs: qs, open: open };
})();
