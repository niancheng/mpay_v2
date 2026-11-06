<?php

declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use app\common\constant\PaymentPluginStatusConstant;
use app\common\payment\AlipayStoreBillReceiptPayment;
use app\service\payment\receipt\AlipayStoreReceiptRules as R;

// Real plugin and parent presentation code; deliberately no database or Alipay IO.
$render = new ReflectionMethod(AlipayStoreBillReceiptPayment::class, 'buildReceiptPaymentResult');
$apiOrder = ['pay_no' => 'TEST-RAW-QR', '_env' => 'pc',
    'extra' => ['_protocol_version' => 'v1', '_submit_type' => 'api']];
$meta = ['mode' => 'amount', 'original_amount' => 1234, 'receipt_amount' => 1234,
    'expire_at' => R::date(time() + 300), 'qrcode' => 'https://qr.alipay.com/test-only-snapshot', 'qrcode_image' => ''];
$rawConfig = ['receipt_qrcode_output' => 'raw', 'amount_offset_max' => 0,
    'receipt_qrcode_content' => 'https://qr.alipay.com/test-only-current-config'];
$cases = [
    ['V1 API 原码', [], [], [], 'qrcode'],
    ['V2 API 原码', [], ['extra' => ['_protocol_version' => 'v2', '_submit_type' => 'api', 'payment' => ['method' => 'web']]], [], 'qrcode'],
    ['手机 API 原码', [], ['_env' => 'mobile'], [], 'qrcode'],
    ['缺少开关沿用收银台', ['receipt_qrcode_output' => null], [], [], 'page'],
    ['显式关闭', ['receipt_qrcode_output' => 'cashier'], [], [], 'page'],
    ['未知开关值安全回退', ['receipt_qrcode_output' => 'unexpected'], [], [], 'page'],
    ['页面跳转不变', [], ['extra' => ['_submit_type' => 'page']], [], 'page'],
    ['无提交来源不直出', [], ['extra' => []], [], 'page'],
    ['非易支付协议不直出', [], ['extra' => ['_protocol_version' => 'other']], [], 'page'],
    ['jump 设备不直出', [], ['_env' => ' JUMP '], [], 'page'],
    ['付款码 API 不直出', [], ['extra' => ['payment' => ['method' => 'auth_code']]], [], 'page'],
    ['APP API 不直出', [], ['extra' => ['payment' => ['method' => 'app']]], [], 'page'],
    ['允许偏移但本单未偏移也不直出', ['amount_offset_max' => 1], [], [], 'page'],
    ['历史实付不同不能直出', [], [], ['receipt_amount' => 1235], 'page'],
    ['非正原金额不能直出', [], [], ['original_amount' => 0, 'receipt_amount' => 0], 'page'],
    ['非金额快照不能直出', [], [], ['mode' => 'remark'], 'page'],
    ['图片不冒充二维码内容', [], [], ['qrcode' => '', 'qrcode_image' => 'https://merchant.example.invalid/qr.png'], 'page'],
    ['空白二维码不能直出', [], [], ['qrcode' => '  '], 'page'],
];
foreach ($cases as [$label, $configChanges, $orderChanges, $metaChanges, $expected]) {
    $plugin = storePlugin();
    $plugin->init(storeConfig(changes: array_replace($rawConfig, $configChanges)));
    $order = array_replace_recursive($apiOrder, $orderChanges);
    // array_replace_recursive does not clear a non-empty map with [].
    if (array_key_exists('extra', $orderChanges) && $orderChanges['extra'] === []) $order['extra'] = [];
    $snapshot = array_replace($meta, $metaChanges);
    $result = $render->invoke($plugin, $order, $snapshot);
    check($result['presentation']['pay_page'] === $expected, $label);
    check($result['status'] === PaymentPluginStatusConstant::PENDING && $result['paid_amount'] === null
        && $result['pay_no'] === $order['pay_no'] && $result['chan_order_no'] === '' && $result['chan_trade_no'] === '',
        $label . '：仍等待真实账单，不伪造支付成功');
    check($result['presentation']['pay_params']['_page'] === 'receiptQrcode'
        && $result['presentation']['pay_params']['expire_at'] === $snapshot['expire_at'],
        $label . '：原收银台参数和识别窗口保留');
    if ($expected === 'qrcode') {
        check($result['presentation']['pay_params']['qrcode'] === $meta['qrcode'], $label . '：只返回快照，不读取当前配置换码');
    }
}
$schema = array_column(storePlugin()->getConfigSchema(), null, 'field');
check($schema['receipt_qrcode_output']['value'] === 'cashier', '新增配置默认兼容旧收银台');
check(array_column($schema['receipt_qrcode_output']['options'], 'value') === ['cashier', 'raw'], '后台提供两种二维码接口输出');
check(!isset($schema['receipt_match_mode']), '门店插件继续固定金额匹配');
$legacy = new \app\common\payment\AlipayBillReceiptPayment(new \app\repository\payment\trade\PayOrderRepository(),
    new \app\repository\payment\config\PaymentChannelRepository(), new \app\repository\payment\config\PaymentTypeRepository());
check(!in_array('receipt_qrcode_output', array_column($legacy->getConfigSchema(), 'field'), true), '普通支付宝账单插件不受影响');
echo "PASS: $checks raw QR presentation unit checks\n";
