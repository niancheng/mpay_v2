/* Read-only recovery for the distributed cashier bundle. Never submits or recreates a payment. */
(() => {
  'use strict';
  const pendingText = '支付结果正在确认中，请勿重复支付。';
  let state = null;

  function stop() {
    if (!state) return;
    state.stopped = true;
    clearTimeout(state.timer);
    if (state.controller) state.controller.abort();
    state = null;
  }

  function manualRefresh() {
    if (document.getElementById('mpay-presentation-refresh')) return;
    const button = document.createElement('button');
    button.id = 'mpay-presentation-refresh';
    button.type = 'button';
    button.textContent = '付款信息已更新，点击刷新';
    button.addEventListener('click', () => window.location.reload());
    document.getElementById('app')?.appendChild(button);
  }

  async function probe(current) {
    if (current.stopped || state !== current || document.hidden) return;
    current.controller = new AbortController();
    const timeout = setTimeout(() => current.controller?.abort(), 12000);
    try {
      const response = await fetch('/api/cashier/pay-order?pay_no=' + encodeURIComponent(current.payNo), {
        method: 'GET', credentials: 'same-origin', cache: 'no-store',
        headers: { Accept: 'application/json' }, signal: current.controller.signal,
      });
      if (!response.ok) throw new Error('Payment context unavailable');
      const payload = await response.json();
      if (current.stopped || state !== current || window.location.pathname !== current.path) return;
      const data = payload?.data;
      if (payload?.code !== 200 || data?.order?.pay_no !== current.payNo
          || typeof data.presentation_pending !== 'boolean') throw new Error('Invalid payment context');
      current.delay = 3000;
      if (!data.presentation_pending) {
        // Status may still be PAYING; presentation readiness, not status change, is decisive.
        current.stopped = true;
        const key = 'mpay:presentation-refreshed:' + current.payNo;
        try {
          if (sessionStorage.getItem(key)) { manualRefresh(); return; }
          sessionStorage.setItem(key, '1'); // No credentials or payment material is persisted.
        } catch (_) { manualRefresh(); return; }
        window.location.reload();
      }
    } catch (_) {
      // Network/challenge/invalid JSON failures never trigger payment submission or a redirect.
      current.delay = Math.min(current.delay * 2, 30000);
    } finally {
      clearTimeout(timeout);
      current.controller = null;
      if (!current.stopped && state === current) current.timer = setTimeout(() => probe(current), current.delay);
    }
  }

  function sync() {
    const path = window.location.pathname;
    const match = /^\/payment\/([A-Za-z0-9_-]{1,64})\/?$/.exec(path);
    const waiting = document.getElementById('app')?.textContent.includes(pendingText);
    if (!match || !waiting || document.hidden) { stop(); return; }
    if (state?.path === path) return;
    stop();
    state = { path, payNo: match[1], delay: 3000, stopped: false, timer: null, controller: null };
    const current = state;
    current.timer = setTimeout(() => probe(current), 0);
  }

  const observer = new MutationObserver(sync);
  observer.observe(document.documentElement, { childList: true, subtree: true, characterData: true });
  window.addEventListener('popstate', sync);
  window.addEventListener('pageshow', sync);
  window.addEventListener('pagehide', stop);
  document.addEventListener('visibilitychange', sync);
  sync();
})();
