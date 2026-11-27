/* Signing, corrections and certificates for one treatment. Works on the standalone page and inside the studio workspace.
   Markup: #s_sign[data-admin="1"] with #state #fsign #pw #mfa #osign #clr #on #od #pad #corr #cf #cv #cr #certs (+ admin: #issue #resend #pack). */
window.ApSigning = (function () {
  'use strict';
  var U = ApUi, id = null, pad = null, admin = false, $ = function (x) { return document.getElementById(x); };
  function load() {
    U.get('sign.php', { treatment_id: id }).then(function (s) {
      $('state').innerHTML = '<p>' + (s.locked ? 'Signed and locked.' : 'Not signed yet.') + ' ' + (s.fully_signed ? '<strong>Both signatures are in.</strong>' : '') + '</p>' +
        (s.signatures || []).map(function (x) { return '<p>' + U.esc(x.role) + ': ' + U.esc(x.name) + ' - ' + U.fiji(x.signed_at) + '</p>'; }).join('');
    }, U.fail);
    if (admin) {
      U.get('certificates.php', { treatment_id: id }).then(function (r) {
        U.table($('certs'), r, [{ label: 'Container', key: 'container_no' }, { label: 'Result', render: function (x) { return '<span class="ap-badge ' + U.esc(x.result) + '">' + U.esc(x.result || '-') + '</span>'; } },
          { label: 'Certificate', render: function (x) { return U.esc(x.number || '-'); } }, { label: 'Emailed', render: function (x) { return x.emailed_at ? U.fiji(x.emailed_at) : (x.email_pending ? 'pending' : ''); } },
          { label: 'Release', render: function (x) { return U.esc(typeof x.release === 'object' ? JSON.stringify(x.release) : x.release); } }]);
      }, U.fail);
    } else { $('certs').innerHTML = '<p class="ap-muted">Certificates are issued by the office once both signatures are in.</p>'; }
  }
  function start(treatmentId) {
    id = treatmentId; api.started = true; admin = $('s_sign').getAttribute('data-admin') === '1';
    pad = ApSignPad($('pad'));
    $('fsign').onclick = function () { U.post('sign.php', { action: 'fumigator_sign', treatment_id: id, password: $('pw').value, mfa_code: $('mfa').value }).then(function () { $('pw').value = ''; U.toast('Signed'); load(); }, U.fail); };
    $('osign').onclick = function () {
      var png = pad.toPng(); if (!png) { U.toast('The officer has not signed yet', true); return; }
      U.post('sign.php', { action: 'officer_sign', treatment_id: id, name: $('on').value, designation: $('od').value, png: png }).then(function () { pad.clear(); U.toast('Signed'); load(); }, U.fail);
    };
    $('clr').onclick = function () { pad.clear(); };
    if (admin) {
      $('corr').onclick = function () {
        var ch = {}; ch[$('cf').value] = $('cv').value;
        U.post('sign.php', { action: 'correct', treatment_id: id, changes: JSON.stringify(ch), reason: $('cr').value }).then(function (r) { U.toast('Corrected: version ' + r.version + '. Sign again.'); load(); }, U.fail);
      };
      $('issue').onclick = function () { U.post('certificates.php', { action: 'issue', treatment_id: id }).then(function (r) { U.toast('Issued ' + (r.issued || []).length + ', waiting ' + (r.skipped || []).length); load(); }, U.fail); };
      $('resend').onclick = function () { U.post('certificates.php', { action: 'resend_pending' }).then(function (r) { U.toast('Sent ' + r.sent); load(); }, U.fail); };
      $('pack').href = (window.AP_API || '../../api/allpest/') + 'audit_pack.php?treatment_id=' + encodeURIComponent(id);
    }
    load();
  }
  var api = { start: start, started: false };
  return api;
})();
