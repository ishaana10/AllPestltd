/* Signing, corrections and certificates for one treatment. Works on the standalone page and inside the studio workspace.
   Markup: #s_sign[data-admin="1"] with #state #fsign #pw #mfa #osign #clr #on #od #pad #corr #cf #cv #cr #certs (+ admin: #issue #resend #pack). */
window.ApSigning = (function () {
  'use strict';
  var U = ApUi, id = null, pad = null, admin = false, $ = function (x) { return document.getElementById(x); };
  function note(anchorId, msg, bad) {
    var a = $(anchorId); if (!a) { U.toast(msg, bad); return; }
    var n = $('smsg_' + anchorId);
    if (!n) { n = document.createElement('p'); n.id = 'smsg_' + anchorId; n.setAttribute('role', 'status'); a.parentNode.insertBefore(n, a.nextSibling); }
    n.textContent = msg; n.style.color = bad ? '#b91c1c' : '#15803d'; n.style.fontWeight = '600';
    U.toast(msg, bad);
  }
  function failNote(anchorId) { return function (e) { note(anchorId, e && e.message ? e.message : String(e), true); }; }
  function load() {
    U.get('sign.php', { treatment_id: id }).then(function (s) {
      $('state').innerHTML = '<p>' + (s.locked ? 'Signed and locked.' : 'Not signed yet.') + ' ' + (s.fully_signed ? '<strong>Both signatures are in.</strong>' : '') + '</p>' +
        (s.signatures || []).map(function (x) { return '<p>' + U.esc(x.role) + ': ' + U.esc(x.name) + ' - ' + U.fiji(x.signed_at) + '</p>'; }).join('');
    }, U.fail);
    if (admin) {
      U.get('certificates.php', { treatment_id: id }).then(function (r) {
        U.table($('certs'), r, [{ label: 'Container', key: 'container_no' }, { label: 'Result', render: function (x) { return '<span class="ap-badge ' + U.esc(x.result) + '">' + U.esc(x.result || '-') + '</span>'; } },
          { label: 'Certificate', render: function (x) { return U.esc(x.number || '-'); } }, { label: 'Emailed', render: function (x) { return x.emailed_at ? U.fiji(x.emailed_at) : (x.email_pending ? 'pending' : ''); } },
          { label: 'Release', render: function (x) { var r = x.release; if (r && typeof r === 'object') { return r.ok ? 'Ready' : 'Waiting for: ' + U.esc((r.missing || []).join(', ').replace(/_/g, ' ')); } return U.esc(r); } },
          { label: 'PDF', render: function (x) { return x.number ? '<a href="' + (window.AP_API || '../../api/allpest/') + 'certificates.php?pdf=1&container_id=' + encodeURIComponent(x.id) + '" target="_blank" rel="noopener">View</a>' : ''; } }]);
      }, U.fail);
    } else { $('certs').innerHTML = '<p class="ap-muted">Certificates are issued by the office once both signatures are in.</p>'; }
  }
  function start(treatmentId) {
    id = treatmentId; api.started = true; admin = $('s_sign').getAttribute('data-admin') === '1';
    pad = ApSignPad($('pad'));
    $('fsign').onclick = function () { U.post('sign.php', { action: 'fumigator_sign', treatment_id: id, password: $('pw').value, mfa_code: $('mfa').value }).then(function (r) { $('pw').value = ''; $('mfa').value = ''; note('fsign', r && r.recorded === false ? 'You had already signed this version.' : 'Signed as fumigator.', false); load(); }, failNote('fsign')); };
    $('osign').onclick = function () {
      var png = pad.toPng(); if (!png) { note('osign', 'The officer has not signed yet: draw the signature first.', true); return; }
      U.post('sign.php', { action: 'officer_sign', treatment_id: id, name: $('on').value, designation: $('od').value, png: png }).then(function (r) { pad.clear(); note('osign', r && r.recorded === false ? 'An officer signature is already recorded for this version.' : 'Officer signature saved (the pad is cleared; it is listed under Signatures above).', false); load(); }, failNote('osign'));
    };
    $('clr').onclick = function () { pad.clear(); };
    if (admin) {
      $('corr').onclick = function () {
        var ch = {}; ch[$('cf').value] = $('cv').value;
        U.post('sign.php', { action: 'correct', treatment_id: id, changes: JSON.stringify(ch), reason: $('cr').value }).then(function (r) { U.toast('Corrected: version ' + r.version + '. Sign again.'); load(); }, U.fail);
      };
      $('issue').onclick = function () { U.post('certificates.php', { action: 'issue', treatment_id: id }).then(function (r) { var sk = r.skipped || {}, ids = Array.isArray(sk) ? sk : Object.keys(sk), why = {};
          ids.forEach(function (k) { (Array.isArray(sk) ? [] : (sk[k] || [])).forEach(function (m) { why[String(m).replace(/_/g, ' ')] = 1; }); });
          U.toast('Issued ' + (r.issued || []).length + (ids.length ? ', ' + ids.length + ' not ready' + (Object.keys(why).length ? ' (waiting for: ' + Object.keys(why).join(', ') + ')' : '') : '')); load(); }, U.fail); };
      $('resend').onclick = function () { U.post('certificates.php', { action: 'resend_pending' }).then(function (r) { U.toast('Sent ' + r.sent); load(); }, U.fail); };
      $('pack').href = (window.AP_API || '../../api/allpest/') + 'audit_pack.php?treatment_id=' + encodeURIComponent(id);
    }
    load();
  }
  var api = { start: start, started: false };
  return api;
})();
