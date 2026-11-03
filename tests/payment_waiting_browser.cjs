'use strict';
// Browser test of the actual distributed cashier bundle; all payment APIs are local fixtures.
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.MPAY_PLAYWRIGHT_PATH || 'playwright');
const root = path.resolve(__dirname, '../public');
let ready = false, documents = 0;
const writes = [], errors = [];
const config = { enabled: true, site_name: '测试商户', title: '支付测试', logo: '/assets/brand/mpay-logo.svg',
  notice_enabled: false, show_merchant_name: true, show_order_no: true, show_pay_type_desc: true,
  poll_interval_seconds: 2, poll_timeout_seconds: 300, customer_service_enabled: false };
function context() {
  return {
    order: { pay_no: 'PAYWAIT', biz_no: 'BIZWAIT', subject: '重复支付测试', amount: 10000, currency: 'CNY',
      status: 1, status_text: '支付中', return_url: '', created_at: new Date().toISOString(),
      expire_at: new Date(Date.now() + 300000).toISOString(), updated_at: new Date().toISOString() },
    merchant: { merchant_id: 1, merchant_name: '测试商户', merchant_no: '1', merchant_short_name: '测试' },
    payment_type: { id: 1, code: 'alipay', name: '支付宝', icon: '' },
    presentation: ready
      ? { pay_page: 'qrcode', pay_type: 'alipay', pay_params: { qrcode: 'TEST-ONLY-NOT-A-PAYMENT', amount: '100.00' } }
      : { pay_page: 'page', pay_type: 'alipay', pay_params: { _page: 'paymentPending', description: '支付结果正在确认中，请勿重复支付。' } },
    presentation_pending: !ready, cashier_path: '/cashier/BIZWAIT', payment_path: '/payment/PAYWAIT', public_config: config,
  };
}
const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://127.0.0.1');
  if (req.method !== 'GET') { writes.push(req.url); res.writeHead(405).end(); return; }
  res.setHeader('Cache-Control', 'no-store');
  if (url.pathname.startsWith('/api/')) {
    let data;
    if (url.pathname === '/api/cashier/config') data = config;
    else if (url.pathname === '/api/cashier/pay-order') data = context();
    else if (url.pathname === '/api/cashier/pay-order-status') data = { pay_no: 'PAYWAIT', status: 1, status_text: '支付中' };
    else { res.writeHead(404).end(); return; }
    res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify({ code: 200, msg: 'success', data })); return;
  }
  let file;
  if (url.pathname === '/payment/PAYWAIT') { ++documents; file = path.join(root, 'cashier/index.html'); }
  else file = path.resolve(root, '.' + url.pathname);
  if (!file.startsWith(root + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { res.writeHead(404).end(); return; }
  res.setHeader('Content-Type', ({ '.js': 'application/javascript', '.css': 'text/css', '.html': 'text/html', '.svg': 'image/svg+xml' })[path.extname(file)] || 'application/octet-stream');
  fs.createReadStream(file).pipe(res);
});
(async () => {
  let browser;
  try {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const origin = `http://127.0.0.1:${server.address().port}`;
    browser = await chromium.launch({ headless: true });
    for (const viewport of [{ width: 1280, height: 900 }, { width: 390, height: 844 }]) {
      ready = false; documents = 0;
      const session = await browser.newContext({ viewport });
      const page = await session.newPage();
      page.on('pageerror', error => errors.push(error.message));
      await page.route('**/*', route => route.request().url().startsWith(origin + '/') ? route.continue() : route.abort());
      await page.goto(origin + '/payment/PAYWAIT');
      await page.getByText('支付结果正在确认中，请勿重复支付。', { exact: true }).waitFor({ timeout: 15000 });
      ready = true; // Order status deliberately remains PAYING throughout.
      await page.waitForFunction(() => !document.getElementById('app').textContent.includes('支付结果正在确认中，请勿重复支付。')
        && document.getElementById('app').textContent.includes('100.00'), undefined, { timeout: 15000 });
      assert.equal(documents, 2, 'waiting page should reload once when payment material arrives');
      await new Promise(resolve => setTimeout(resolve, 3500));
      assert.equal(documents, 2, 'no reload loop after recovery');
      assert.deepEqual(writes, [], 'no payment submission or other write request');
      assert.deepEqual(errors, [], 'actual cashier bundle must render without JS errors');
      console.log(`PASS actual cashier waiting -> ready at ${viewport.width}x${viewport.height}`);
      await session.close();
    }
  } finally {
    if (browser) await browser.close();
    await new Promise(resolve => server.close(resolve));
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
