/* Keeps unsent reading sets in localStorage and resubmits them in order when the connection returns.
   Each set has a client UUID; the server stores a set once per UUID, so a resend after a lost reply is safe. */
window.ApQueue = (function () {
  'use strict';
  var KEY = 'allpest.queue.v1', busy = false, listeners = [];
  function load() { try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { return []; } }
  function save(q) { try { localStorage.setItem(KEY, JSON.stringify(q)); } catch (e) { throw new Error('This device is out of storage; connect to send the saved readings first.'); } notify(); }
  function loadRej() { try { return JSON.parse(localStorage.getItem(KEY + '.rejected') || '[]'); } catch (e) { return []; } }
  function notify() { var n = load().length, r = loadRej().length; listeners.forEach(function (f) { f(n, r); }); }
  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    var b = new Uint8Array(16); crypto.getRandomValues(b); b[6] = (b[6] & 15) | 64; b[8] = (b[8] & 63) | 128;
    var h = Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
    return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
  }
  /** item: {client_uuid, treatment_id, stage, at, values, initials, photos:[dataUrl], file_ids:[]} */
  function enqueue(item) { var q = load(); q.push(item); save(q); }
  function send(item) {
    var up = (item.photos || []).map(function (d, i) {
      if (item.file_ids && item.file_ids[i]) return Promise.resolve(item.file_ids[i]);
      return ApCompress.fromDataUrl(d).then(function (b) {
        var f = new FormData(); f.append('purpose', 'reading_photo'); f.append('file', b, 'reading.jpg');
        return fetch((window.AP_API || '../../api/allpest/') + 'upload.php', { method: 'POST', body: f, credentials: 'same-origin' }).then(function (r) { return r.json().then(function (j) { if (!r.ok) { var e = new Error(j.error || 'Upload failed'); e.fatal = r.status >= 400 && r.status < 500 && r.status !== 401 && r.status !== 429; throw e; } return j.file_id; }); });
      });
    });
    return Promise.all(up).then(function (ids) {
      item.file_ids = ids;
      var f = new FormData();
      f.append('action', 'readings'); f.append('treatment_id', item.treatment_id); f.append('client_uuid', item.client_uuid); f.append('stage', item.stage);
      f.append('at', item.at); f.append('values', JSON.stringify(item.values)); f.append('initials', item.initials);
      ids.forEach(function (id) { f.append('file_ids[]', id); });
      return fetch((window.AP_API || '../../api/allpest/') + 'treatments.php', { method: 'POST', body: f, credentials: 'same-origin' }).then(function (r) {
        return r.json().then(function (j) { if (!r.ok) { var e = new Error(j.error || 'Rejected'); e.fatal = r.status >= 400 && r.status < 500 && r.status !== 401 && r.status !== 429; e.status = r.status; throw e; } return j; });
      });
    });
  }
  /** Sends queued items oldest first and stops at the first network failure. A set the server rejects is moved to the rejected list so it cannot block the rest. */
  function flush(onResult) {
    if (busy) return Promise.resolve(); busy = true;
    function next() {
      var q = load(); if (!q.length) { busy = false; return Promise.resolve(); }
      return send(q[0]).then(function (res) { var q2 = load(); q2.shift(); save(q2); if (onResult) onResult(q[0], res, null); return next(); }, function (err) {
        if (!err.fatal && err.status >= 500) { var q5 = load(); if (q5[0]) { q5[0].fails = (q5[0].fails || 0) + 1; q5[0].file_ids = q[0].file_ids; if (q5[0].fails >= 5) { err.fatal = true; err.message = 'The server kept failing (' + err.message + '). Re-enter this reading set.'; } else { save(q5); } } }
        if (err.fatal) { var q3 = load(); var bad = q3.shift(); save(q3); var rej = JSON.parse(localStorage.getItem(KEY + '.rejected') || '[]'); bad.error = err.message; rej.push(bad); localStorage.setItem(KEY + '.rejected', JSON.stringify(rej)); notify(); if (onResult) onResult(bad, null, err); return next(); }
        var q4 = load(); if (q4[0]) { q4[0].file_ids = q[0].file_ids; save(q4); }
        busy = false; return Promise.resolve();
      });
    }
    return next();
  }
  window.addEventListener('online', function () { flush(); });
  // Anything saved on this device by an earlier visit is sent as soon as a page loads.
  setTimeout(function () { flush(); }, 0);
  function statusText(n, r) {
    var t = '';
    if (n) t += n + ' reading set(s) saved on this device, waiting to send. ';
    if (r) t += r + ' reading set(s) were REJECTED by the server and are NOT recorded (' + loadRej().map(function (x) { return (x.stage || '') + ': ' + (x.error || 'rejected'); }).join('; ') + '). Re-enter them.';
    return t;
  }
  return { statusText: statusText, uuid: uuid, enqueue: enqueue, flush: flush, size: function () { return load().length; }, rejected: function () { try { return JSON.parse(localStorage.getItem(KEY + '.rejected') || '[]'); } catch (e) { return []; } }, onChange: function (f) { listeners.push(f); f(load().length, loadRej().length); }, clearRejected: function () { try { localStorage.setItem(KEY + '.rejected', '[]'); } catch (e) {} notify(); } };
})();
