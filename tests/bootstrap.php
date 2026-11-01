<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
\Webman\Config::load(dirname(__DIR__) . '/config', ['route', 'process', 'bootstrap']);
date_default_timezone_set('Asia/Shanghai');
foreach ((array) config('autoload.files', []) as $file) require_once $file;
@mkdir(runtime_path(), 0777, true);

$checks = 0;
function check(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) throw new RuntimeException('FAILED: ' . $label);
    ++$checks;
    echo 'ok ' . $checks . ' - ' . $label . PHP_EOL;
}
function rejects(callable $call, string $label): void
{
    $error = null;
    try { $call(); } catch (Throwable $e) { $error = $e; }
    check($error !== null, $label);
}
function storeConfig(string $store = '店铺1793551172076', array $changes = []): array
{
    return array_replace(['app_id' => '2026110200000001', 'bill_user_id' => '2088000000000001',
        'private_key' => 'TEST-ONLY-NOT-A-REAL-KEY', 'alipay_public_key' => 'TEST-ONLY-NOT-A-REAL-KEY',
        'store_no' => $store, 'receipt_qrcode_content' => 'test-only-qr-content',
        'receipt_valid_seconds' => 300, 'amount_offset_max' => 0, 'receipt_watcher_query_interval_seconds' => 3], $changes);
}
function storePlugin(): \app\common\payment\AlipayStoreBillReceiptPayment
{
    return new \app\common\payment\AlipayStoreBillReceiptPayment(new \app\repository\payment\trade\PayOrderRepository(),
        new \app\repository\payment\config\PaymentChannelRepository(), new \app\repository\payment\config\PaymentTypeRepository());
}
function testFlow(string $trade = '2026110223001436181445487467', array $changes = []): array
{
    $now = time();
    return array_replace(['source_method' => 'alipay.data.bill.sell.query', 'pay_type' => 'alipay',
        'bill_user_id' => '2088000000000001', 'store_no' => '店铺1793551172076', 'trade_status' => '成功',
        'order_no' => $trade, 'candidate_pay_no' => 'TEST-P1', 'candidate_channel_id' => 1, 'price' => '0.20',
        'refund_amount' => '0.00', 'net_mdiscount' => '0.00', 'service_fee' => '0.00',
        'created_at' => $now - 30, 'paid_at' => $now - 10, 'remark' => '', 'bill_type' => '即时到帐'], $changes);
}
