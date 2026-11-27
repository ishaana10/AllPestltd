// Dependency-free test of assets/js/allpest/queue.js (run: node tests/js/queue.test.js)
const fs = require('fs'), vm = require('vm'), assert = require('assert');
const src = fs.readFileSync(__dirname + '/../../assets/js/allpest/queue.js', 'utf8');

function boot(fetchImpl, preload) {
  const store = Object.assign({}, preload || {});
  const handlers = {};
  const win = { addEventListener: (e, f) => { (handlers[e] = handlers[e] || []).push(f); }, crypto: require('crypto').webcrypto };
  const ctx = {
    window: win, crypto: win.crypto, document: { readyState: 'complete', addEventListener: (e, f) => { (handlers['doc:' + e] = handlers['doc:' + e] || []).push(f); } },
    localStorage: { getItem: k => (k in store ? store[k] : null), setItem: (k, v) => { store[k] = String(v); } },
    fetch: fetchImpl, FormData: class { constructor() { this.d = []; } append(k, v) { this.d.push([k, v]); } }, ApCompress: { fromDataUrl: () => Promise.resolve('b') },
    console, setTimeout,
  };
  win.window = win; vm.createContext(ctx); ctx.window = win; Object.assign(win, ctx);
  vm.runInContext(src, ctx);
  return { q: win.ApQueue, store, handlers };
}
const item = (n) => ({ client_uuid: 'u' + n, treatment_id: 't', stage: 'start_point', at: 'x', values: {}, initials: 'TO', photos: [], file_ids: [] });
const resp = (status, body) => Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });

(async () => {
  // 1. a rejected set is reported to listeners and kept for the technician to see
  let h = boot(() => resp(422, { error: 'Bad reading' }));
  let seen = [];
  h.q.onChange((n, rej) => seen.push([n, rej]));
  h.q.enqueue(item(1));
  await h.q.flush();
  assert.strictEqual(h.q.rejected().length, 1, 'rejected set kept');
  assert.strictEqual(h.q.rejected()[0].error, 'Bad reading');
  assert.deepStrictEqual(seen[seen.length - 1], [0, 1], 'listeners told about the rejected set');

  // 2. a persistent server error (5xx) cannot block the queue forever
  let calls = 0;
  h = boot(() => { calls++; return resp(500, { error: 'boom' }); });
  h.q.enqueue(item(1));
  for (let i = 0; i < 6; i++) await h.q.flush();
  assert.strictEqual(h.q.size(), 0, 'stuck head moved out after repeated server errors');
  assert.strictEqual(h.q.rejected().length, 1);

  // 3. a network failure never discards a set
  h = boot(() => Promise.reject(new TypeError('offline')));
  h.q.enqueue(item(1));
  for (let i = 0; i < 8; i++) await h.q.flush();
  assert.strictEqual(h.q.size(), 1, 'offline sets stay queued');
  assert.strictEqual(h.q.rejected().length, 0);

  // 4. queued sets are sent when the page loads
  let sent = 0;
  h = boot(() => { sent++; return resp(200, { standard: 1 }); }, { 'allpest.queue.v1': JSON.stringify([item(9)]) });
  await new Promise(r => setTimeout(r, 20));
  assert.ok(sent >= 1, 'flushed on load');
  assert.strictEqual(h.q.size(), 0);
  console.log('queue.js OK');
})().catch(e => { console.error(e); process.exit(1); });
