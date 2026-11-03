<?php

declare(strict_types=1);
require __DIR__ . '/store_routing_helpers.php';

use app\model\merchant\MerchantApiCredential;
use app\model\payment\PayOrder;
use app\service\payment\epay\EpaySignerManager;
use app\service\payment\epay\EpayV1ProtocolService;
use support\Container;
use support\Request;

function reuseV1Signed(array $payload, string $key): array
{
    unset($payload['sign'], $payload['sign_type']);
    return $payload + ['sign' => Container::get(EpaySignerManager::class)->sign($payload, 'MD5', $key), 'sign_type' => 'MD5'];
}
function reuseV1Location(\support\Response $response): string
{
    preg_match('/^Location:\s*([^\r\n]+)/mi', (string) $response, $match);
    return $match[1] ?? '';
}

// Real signature verification and local receipt plugin; no real Alipay network request.
$request = new Request("GET / HTTP/1.1\r\nHost: localhost\r\nUser-Agent: Mozilla/5.0\r\n\r\n");
$pf = routingFixture(1); $key = bin2hex(random_bytes(16));
$credential = MerchantApiCredential::create(['merchant_id' => $pf['merchant']->id, 'api_key' => $key, 'status' => 1]);
$p1 = Container::get(EpayV1ProtocolService::class);
$payload = reuseV1Signed([
    'pid' => (string) $pf['merchant']->id, 'type' => 'alipay', 'out_trade_no' => 'V1-' . bin2hex(random_bytes(8)),
    'money' => '100.00', 'name' => '重复支付协议测试', 'notify_url' => 'https://merchant.example.invalid/notify',
    'return_url' => 'https://merchant.example.invalid/return', 'clientip' => '127.0.0.1', 'device' => 'pc',
], $key);
$location = reuseV1Location($p1->submit($payload, $payload, $request));
check(str_contains($location, '/payment/PAY') && !str_contains($location, '/entry/'), 'V1 首次签名页面提交成功');
$counts = routingCounts();
check(reuseV1Location($p1->submit($payload, $payload, $request)) === $location, 'V1 页面重复提交跳回同一支付页');
$mapi = $p1->mapi($payload, $payload, $request);
check($mapi['code'] === 1 && str_ends_with($location, '/payment/' . $mapi['trade_no']) && routingCounts() === $counts,
    'V1 API 复用相同 trade_no，不受 submit_type 变化影响');
$bad = $payload; $bad['sign'] = 'invalid';
check($p1->mapi($bad, $bad, $request)['code'] === 0, 'V1 已有原单仍要求有效签名');
$credential->status = 0; $credential->save();
check($p1->mapi($payload, $payload, $request)['code'] === 0, 'V1 已有原单仍拒绝停用凭证');
$credential->status = 1; $credential->save();
$pf['merchant']->pay_status = 0; $pf['merchant']->save();
check($p1->mapi($payload, $payload, $request)['code'] === 0, 'V1 已有原单仍拒绝停用支付商户');
$pf['merchant']->pay_status = 1; $pf['merchant']->save();
$bad = reuseV1Signed(array_replace($payload, ['money' => '101.00']), $key);
check(str_contains(reuseV1Location($p1->submit($bad, $bad, $request)), '/payment/entry/error?'), 'V1 合法签名但金额冲突不跳入原支付页');
check(routingCounts() === $counts, 'V1 鉴权与参数冲突不产生副作用');

// A committed attempt without material must still be a valid MAPI result, not a second dispatch.
$pay = PayOrder::where('pay_no', $mapi['trade_no'])->firstOrFail();
$ext = $pay->ext_json; unset($ext['presentation']); $pay->ext_json = $ext; $pay->save();
$waiting = $p1->mapi($payload, $payload, $request);
check($waiting['code'] === 1 && $waiting['trade_no'] === $pay->pay_no && $waiting['payurl'] === $location,
    'V1 首次资料尚未就绪时返回原单承接链接');
check(routingCounts() === $counts && !isset($pay->fresh()->ext_json['presentation']),
    'V1 等待重入不调用插件重写资料，也不新增资金记录');
echo "PASS: $checks signed V1 page/API reuse checks\n";
