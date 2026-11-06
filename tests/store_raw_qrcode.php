<?php

declare(strict_types=1);
require __DIR__ . '/store_routing_helpers.php';

use app\model\merchant\MerchantApiCredential;
use app\model\payment\PayOrder;
use app\model\payment\PaymentPluginConf;
use app\service\payment\cashier\CashierService;
use app\service\payment\epay\EpaySignerManager;
use app\service\payment\epay\EpayV1ProtocolService;
use app\service\payment\epay\EpayV2ProtocolService;
use support\Container;
use support\Request;

function rawQrFixture(array $changes = [], int $offset = 0): array
{
    $f = routingFixture(1, $offset);
    $conf = PaymentPluginConf::findOrFail($f['channels'][0]->api_config_id);
    $conf->config = array_replace($conf->config, ['receipt_qrcode_output' => 'raw',
        'receipt_qrcode_content' => 'https://qr.alipay.com/test-only-' . bin2hex(random_bytes(8))], $changes);
    $conf->save();
    $f['config'] = $conf;
    return $f;
}
function rawQrSigned(array $payload, string $key, string $algorithm = 'MD5'): array
{
    unset($payload['sign'], $payload['sign_type']);
    return $payload + ['sign' => Container::get(EpaySignerManager::class)->sign($payload, $algorithm, $key), 'sign_type' => $algorithm];
}
function rawQrPayload(array $f): array
{
    return ['pid' => (string) $f['merchant']->id, 'type' => 'alipay', 'out_trade_no' => 'RAW-' . bin2hex(random_bytes(8)),
        'money' => '100.00', 'name' => '门店原始二维码测试', 'notify_url' => 'https://merchant.example.invalid/notify',
        'return_url' => 'https://merchant.example.invalid/return', 'clientip' => '127.0.0.1', 'device' => 'pc'];
}
function rawQrLocation(\support\Response $response): string
{
    preg_match('/^Location:\s*([^\r\n]+)/mi', (string) $response, $match);
    return $match[1] ?? '';
}
function rawQrV1Credential(array $f): string
{
    $key = bin2hex(random_bytes(16));
    MerchantApiCredential::create(['merchant_id' => $f['merchant']->id, 'api_key' => $key, 'status' => 1]);
    return $key;
}

$request = new Request("GET / HTTP/1.1\r\nHost: localhost\r\nUser-Agent: Mozilla/5.0\r\n\r\n");
$v1 = Container::get(EpayV1ProtocolService::class);
$f = rawQrFixture(); $key = rawQrV1Credential($f);
$payload = rawQrSigned(rawQrPayload($f), $key);
$first = $v1->mapi($payload, $payload, $request);
check($first['code'] === 1 && ($first['qrcode'] ?? '') === $f['config']->config['receipt_qrcode_content']
    && !isset($first['payurl']), 'V1 已签名 API 下单直接返回支付宝码，不返回收银台 URL');
$pay = PayOrder::where('pay_no', $first['trade_no'])->firstOrFail();
$counts = routingCounts(); $attrs = $pay->getAttributes();
check((int) $pay->pay_amount === 10000 && $pay->ext_json['personal_receipt']['receipt_amount'] === 10000,
    '原码输出仍完成原金额分配并保存快照');
check($pay->ext_json['presentation']['pay_page'] === 'qrcode', '标准二维码展示结果通过公共校验并持久化');
for ($i = 0; $i < 3; ++$i) {
    check($v1->mapi($payload, $payload, $request) === $first, 'V1 重复 API 返回相同支付号和原码 ' . $i);
}
$location = rawQrLocation($v1->submit($payload, $payload, $request));
check(str_ends_with($location, '/payment/' . $pay->pay_no), 'API 原单切回页面入口仍跳 mpay，不直跳支付宝');
check(routingCounts() === $counts && $pay->fresh()->getAttributes() === $attrs,
    '重复访问不增加资金记录、不延长有效期、不重新分配金额');
$conf = $f['config']; $savedConfig = $conf->config;
$conf->config = array_replace($savedConfig, ['receipt_qrcode_content' => 'changed-test-only']);
routingRejects(fn () => $conf->save(), \app\exception\PaymentException::class, '原码订单待支付期间仍禁止修改收款二维码');
check($conf->fresh()->config === $savedConfig, '被拒绝的二维码修改没有落库');
$conf = $conf->fresh();
$conf->config = array_replace($savedConfig, ['receipt_qrcode_output' => 'cashier']); $conf->save();
check($v1->mapi($payload, $payload, $request) === $first, '展示开关改变后原单仍复用已经保存的二维码，不偷偷换码');
$conf->config = $savedConfig; $conf->save();
$bad = $payload; $bad['sign'] = 'invalid';
check($v1->mapi($bad, $bad, $request)['code'] === 0, '原码订单的重复请求仍要求有效签名');
$occupied = rawQrSigned(rawQrPayload($f), $key);
check($v1->mapi($occupied, $occupied, $request)['code'] === 0 && routingCounts() === $counts,
    '同门店同金额已占用仍拒绝，不因直出二维码放开并发唯一性');

// Fresh page submissions retain their exact custom receipt presentation, even with raw output enabled.
$pageFixture = rawQrFixture(); $pageKey = rawQrV1Credential($pageFixture);
$pagePayload = rawQrSigned(rawQrPayload($pageFixture), $pageKey);
$pageLocation = rawQrLocation($v1->submit($pagePayload, $pagePayload, $request));
$pageApi = $v1->mapi($pagePayload, $pagePayload, $request);
$pagePay = PayOrder::where('pay_no', $pageApi['trade_no'])->firstOrFail();
check($pageLocation === ($pageApi['payurl'] ?? '') && !isset($pageApi['qrcode'])
    && $pagePay->ext_json['presentation']['pay_page'] === 'page'
    && $pagePay->ext_json['presentation']['pay_params']['_page'] === 'receiptQrcode',
    'V1 首次页面跳转及页面原单复用保持旧收银台');

foreach ([
    ['默认收银台', ['receipt_qrcode_output' => null], 0, false],
    ['仅图片', ['receipt_qrcode_content' => '', 'receipt_qrcode_image' => 'https://merchant.example.invalid/qr.png'], 0, false],
    ['允许偏移但未发生偏移', [], 1, false],
    ['实际偏移一分', [], 1, true],
] as [$label, $changes, $offset, $occupy]) {
    $ff = rawQrFixture($changes, $offset); $fk = rawQrV1Credential($ff);
    if ($occupy) routingOccupy($ff['channels'][0]);
    $fp = rawQrSigned(rawQrPayload($ff), $fk);
    $response = $v1->mapi($fp, $fp, $request);
    check($response['code'] === 1 && isset($response['payurl']) && !isset($response['qrcode']), $label . '：安全回退收银台');
    $row = PayOrder::where('pay_no', $response['trade_no'])->firstOrFail();
    check((int) $row->pay_amount === ($occupy ? 10001 : 10000), $label . '：金额分配规则不变');
}

// Existing expiry/terminal handling must never leak a payable raw QR on reuse.
$pay->expire_at = date('Y-m-d H:i:s', time() - 1); $pay->save();
$expiredAttrs = $pay->fresh()->getAttributes(); $expiredCounts = routingCounts();
$expired = $v1->mapi($payload, $payload, $request);
check($expired['code'] === 1 && !isset($expired['qrcode']) && ($expired['payurl'] ?? '') === $location,
    '过期原码订单只返回本站状态承接地址，不再下发支付宝码');
$detail = Container::get(CashierService::class)->payOrderDetail((string) $pay->pay_no);
check($detail['presentation']['pay_page'] === 'error' && !isset($detail['presentation']['pay_params']['qrcode']), '过期订单页面也不泄露付款码');
check($pay->fresh()->getAttributes() === $expiredAttrs && routingCounts() === $expiredCounts, '过期重入不延时、不新建、不伪造成功');

// V2 uses real RSA request/response signatures with ephemeral test-only keys.
$keyResource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($keyResource === false || !openssl_pkey_export($keyResource, $privateKey)) throw new RuntimeException('Cannot create test RSA key');
$publicKey = openssl_pkey_get_details($keyResource)['key'];
$epayConfig = (array) config('epay');
$temp = sys_get_temp_dir() . '/mpay-raw-qr-config-' . bin2hex(random_bytes(6)); mkdir($temp, 0700);
try {
    file_put_contents($temp . '/app.php', '<?php return [];');
    $testConfig = $epayConfig;
    $testConfig['v2']['platform_private_key'] = $privateKey;
    $testConfig['v2']['platform_public_key'] = $publicKey;
    file_put_contents($temp . '/epay.php', '<?php return ' . var_export($testConfig, true) . ';');
    \Webman\Config::load($temp);
    $v2 = Container::get(EpayV2ProtocolService::class);
    foreach (['api', 'page'] as $mode) {
        $vf = rawQrFixture();
        MerchantApiCredential::create(['merchant_id' => $vf['merchant']->id, 'api_key' => bin2hex(random_bytes(16)),
            'merchant_public_key' => $publicKey, 'status' => 1]);
        $vp = rawQrSigned(rawQrPayload($vf) + ['timestamp' => (string) time(), 'method' => 'web'], $privateKey, 'RSA');
        $pageUrl = $mode === 'page' ? rawQrLocation($v2->submit($vp, $request)) : '';
        $response = $v2->create($vp, $request);
        check($response['code'] === 0, 'V2 ' . $mode . ' 签名请求成功');
        $signed = $response; unset($signed['sign'], $signed['sign_type']);
        check(Container::get(EpaySignerManager::class)->verify($signed, 'RSA', $response['sign'], $publicKey), 'V2 ' . $mode . ' 响应签名有效');
        if ($mode === 'api') {
            check($response['pay_type'] === 'qrcode' && $response['pay_info'] === $vf['config']->config['receipt_qrcode_content'],
                'V2 API 返回标准 pay_type=qrcode 和原始 pay_info');
        } else {
            check($response['pay_type'] === 'jump' && $response['pay_info'] === $pageUrl,
                'V2 页面提交及后续 API 复用仍是原收银台链接');
        }
        $row = PayOrder::where('pay_no', $response['trade_no'])->firstOrFail();
        $saved = $row->getAttributes(); $counts = routingCounts();
        $again = $v2->create($vp, $request);
        check($again['trade_no'] === $response['trade_no'] && $again['pay_type'] === $response['pay_type'] && $again['pay_info'] === $response['pay_info'],
            'V2 ' . $mode . ' 重复请求复用原材料');
        check(str_ends_with(rawQrLocation($v2->submit($vp, $request)), '/payment/' . $row->pay_no), 'V2 ' . $mode . ' 页面入口始终跳回 mpay');
        check($row->fresh()->getAttributes() === $saved && routingCounts() === $counts, 'V2 ' . $mode . ' 重复请求无写库和资金副作用');
    }
} finally {
    file_put_contents($temp . '/epay.php', '<?php return ' . var_export($epayConfig, true) . ';');
    \Webman\Config::load($temp);
    unlink($temp . '/epay.php'); unlink($temp . '/app.php'); rmdir($temp);
}
echo "PASS: $checks raw QR signed protocol/database regression checks\n";
