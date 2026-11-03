'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../public/cashier/payment-reentry.v1.js'), 'utf8');
const pendingText = '支付结果正在确认中，请勿重复支付。';
function harness({ path = '/payment/PAYTEST', waiting = true, hidden = false, storage = new Map(), responses = [], fetcher } = {}) {
  const timers = new Map(), reads = [], elements = new Map(), events = new Map();
  let serial = 0, now = 0, mutation, reloads = 0;
  const app = { textContent: waiting ? pendingText : '二维码', appendChild(button) { elements.set(button.id, button); mutation?.(); } };
  const document = {
    hidden, documentElement: {}, getElementById: id => id === 'app' ? app : elements.get(id),
    createElement: () => ({ addEventListener() {} }), addEventListener: (name, callback) => events.set(name, callback),
  };
  const window = { location: { pathname: path, reload: () => ++reloads }, addEventListener: (name, callback) => events.set(name, callback) };
  const context = {
    window, document, AbortController, encodeURIComponent,
    sessionStorage: { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value) },
    MutationObserver: class { constructor(callback) { mutation = callback; } observe() {} },
    setTimeout: (fn, delay) => { const id = ++serial; timers.set(id, { fn, at: now + delay }); return id; },
    clearTimeout: id => timers.delete(id),
    fetch: async (url, options) => {
      reads.push({ url, options });
      if (fetcher) return fetcher(url, options);
      const data = responses.shift();
      if (data instanceof Error) throw data;
      return { ok: true, json: async () => data };
    },
  };
  vm.runInNewContext(source, context);
  return {
    reads, app, document, window, storage, elements,
    reloads: () => reloads,
    change: () => mutation(), event: name => events.get(name)?.(),
    async advance(ms = 0) {
      const end = now + ms;
      for (let count = 0; count < 100; ++count) {
        const next = [...timers].filter(([, timer]) => timer.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
        if (!next) break;
        timers.delete(next[0]); now = next[1].at; next[1].fn();
        await new Promise(resolve => setImmediate(resolve));
      }
      now = end;
    },
  };
}
const data = (pending, no = 'PAYTEST', status = 1) => ({ code: 200, data: { order: { pay_no: no, status }, presentation_pending: pending } });

test('only waiting payment pages query; cashier, entry errors and ready QR pages do not', async () => {
  for (const options of [{ path: '/submit.php' }, { path: '/cashier/BIZ1' }, { path: '/payment/entry/error' }, { waiting: false }, { hidden: true }]) {
    const h = harness(options); await h.advance(60000); assert.equal(h.reads.length, 0);
  }
});
test('pending -> ready with unchanged PAYING status reloads original page exactly once', async () => {
  const h = harness({ responses: [data(true), data(false)] });
  await h.advance(); assert.equal(h.reloads(), 0);
  await h.advance(3000); assert.equal(h.reloads(), 1);
  await h.advance(60000); assert.equal(h.reads.length, 2);
  for (const read of h.reads) {
    assert.equal(read.url, '/api/cashier/pay-order?pay_no=PAYTEST');
    assert.equal(read.options.method, 'GET'); assert.equal(read.options.cache, 'no-store');
    assert.equal(read.options.credentials, 'same-origin');
  }
  const again = harness({ responses: [data(false)], storage: h.storage });
  await again.advance(); assert.equal(again.reloads(), 0);
  assert.ok(again.elements.has('mpay-presentation-refresh'));
});
test('read failures, challenge responses, missing flag and wrong order never redirect or submit', async () => {
  for (const response of [new Error('challenge HTML / network error'), {}, { code: 200, data: { order: { pay_no: 'PAYTEST' } } }, data(false, 'OTHER')]) {
    const h = harness({ responses: [response] }); await h.advance();
    assert.equal(h.reloads(), 0); assert.equal(h.reads.length, 1);
    await h.advance(3000); assert.equal(h.reads.length, 1); // Backoff, not an immediate retry loop.
  }
});
test('server may already be ready while original rendered view is pending', async () => {
  const h = harness({ responses: [data(false)] }); await h.advance(); assert.equal(h.reloads(), 1);
});
test('terminal results also refresh without inferring successful payment', async () => {
  for (const status of [2, 3, 4, 5]) {
    const h = harness({ responses: [data(false, 'PAYTEST', status)] });
    await h.advance(); assert.equal(h.reloads(), 1); assert.equal(h.reads[0].options.method, 'GET');
  }
});
test('navigation cancels pending reads and cannot reload a different order', async () => {
  let complete;
  const h = harness({ fetcher: () => new Promise(resolve => { complete = resolve; }) });
  await h.advance(); h.window.location.pathname = '/cashier'; h.event('popstate');
  assert.equal(h.reads[0].options.signal.aborted, true);
  complete({ ok: true, json: async () => data(false) });
  await new Promise(resolve => setImmediate(resolve)); assert.equal(h.reloads(), 0);
});
test('hidden page stops polling and visible waiting page resumes read-only', async () => {
  const h = harness({ responses: [data(true), data(false)] }); await h.advance();
  h.document.hidden = true; h.event('visibilitychange'); await h.advance(10000); assert.equal(h.reads.length, 1);
  h.document.hidden = false; h.event('visibilitychange'); await h.advance(); assert.equal(h.reloads(), 1);
});
test('blocked browser storage uses manual refresh instead of a reload loop', async () => {
  const storage = { get() { throw new Error('blocked'); }, set() { throw new Error('blocked'); } };
  const h = harness({ storage, responses: [data(false)] }); await h.advance();
  assert.equal(h.reloads(), 0); assert.ok(h.elements.has('mpay-presentation-refresh'));
  await h.advance(60000); assert.equal(h.reads.length, 1);
});
