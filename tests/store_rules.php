<?php

declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use app\service\payment\receipt\AlipayStoreReceiptRules as R;

$c = R::config(storeConfig());
check($c['receipt_match_mode'] === 'amount' && $c['store_no'] === '店铺1793551172076', '金额模式与中文门店编号');
foreach (['remark', 'alipay_transfer', '', 'unknown'] as $mode) rejects(fn () => R::config(storeConfig(changes: ['receipt_match_mode' => $mode])), '后端禁止模式 ' . $mode);
foreach (['', "店铺\n测试", str_repeat('店', 129)] as $store) rejects(fn () => R::config(storeConfig($store)), '非法门店编号');
foreach (['amount_offset_max' => 100, 'receipt_valid_seconds' => 59, 'receipt_watcher_query_interval_seconds' => 1] as $key => $value) rejects(fn () => R::config(storeConfig(changes: [$key => $value])), '配置边界 ' . $key);
check(R::cents('0.20') === 20 && R::cents('10.01') === 1001, '整数分金额转换');
foreach ([0.2, '1e2', '-0.01', '1.001', '01.00', null] as $money) rejects(fn () => R::cents($money), '拒绝不明确金额');
rejects(fn () => R::timestamp('2026-02-30 10:00:00'), '拒绝非法日期');
check(R::date(R::timestamp('2026-11-02 00:42:06')) === '2026-11-02 00:42:06', 'UTC+8 时间往返');
check(R::allocate(20, [], 0) === 20, '零偏移保留原金额');
rejects(fn () => R::allocate(20, [20], 0), '零偏移冲突拒绝');
check(R::allocate(20, [20, 21, 23], 5) === 22, '选择最小空闲偏移');
rejects(fn () => R::allocate(20, range(20, 119), 99), '偏移用尽不得继续增加');
$f = testFlow();
check(R::flow($f, $c, time())['amount'] === 20, '有效门店流水');
foreach (['source_method' => 'alipay.data.bill.accountlog.query', 'store_no' => '店铺17935511720760', 'bill_user_id' => '2088000000000002', 'trade_status' => '待付款', 'candidate_pay_no' => '', 'candidate_channel_id' => 0, 'price' => '0.00', 'refund_amount' => '0.01', 'net_mdiscount' => '0.01', 'service_fee' => '0.21', 'paid_at' => time()+6, 'created_at' => time()+1] as $key => $value) rejects(fn () => R::flow(array_replace($f, [$key => $value]), $c, time()), '拒绝异常流水 ' . $key);
foreach (['store_no','paid_at','refund_amount','net_mdiscount'] as $key) { $bad = $f; unset($bad[$key]); rejects(fn () => R::flow($bad, $c, time()), '缺失字段 ' . $key); }
check(R::flow(array_replace($f, ['service_fee' => '0.01']), $c, time())['amount'] === 20, '不从订单金额扣除手续费');
$p = storePlugin();
check($p->getCode() === R::PLUGIN && $p->getName() === '支付宝门店账单收款', '插件身份');
$fields = array_column($p->getConfigSchema(), 'field');
check(in_array('store_no', $fields, true) && !in_array('receipt_match_mode', $fields, true), '表单只提供门店与金额参数');
$p->init($c);
rejects(fn () => $p->notify(new \support\Request("POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 0\r\n\r\n")), '拒绝未签名 HTTP 认款');
$legacy = new \app\common\payment\AlipayBillReceiptPayment(new \app\repository\payment\trade\PayOrderRepository(), new \app\repository\payment\config\PaymentChannelRepository(), new \app\repository\payment\config\PaymentTypeRepository());
$mode = array_values(array_filter($legacy->getConfigSchema(), fn ($f) => $f['field'] === 'receipt_match_mode'))[0];
check(array_column($mode['options'], 'value') === ['amount','remark','alipay_transfer'], '原插件三种模式保留');
echo "PASS: $checks pure PHP checks\n";
