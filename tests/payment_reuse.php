<?php

declare(strict_types=1);
require __DIR__ . '/store_routing_helpers.php';

use app\common\constant\RouteConstant;
use app\exception\BusinessStateException;
use app\exception\ConflictException;
use app\exception\PaymentUncertainException;
use app\exception\ValidationException;
use app\model\merchant\Merchant;
use app\model\merchant\MerchantApiCredential;
use app\model\payment\BizOrder;
use app\model\payment\PayOrder;
use app\model\payment\PaymentChannel;
use app\model\payment\PaymentPluginConf;
use app\model\payment\PaymentType;
use app\service\payment\cashier\CashierService;
use app\service\payment\epay\EpaySignerManager;
use app\service\payment\epay\EpayV1ProtocolService;
use app\service\payment\epay\EpayV2ProtocolService;
use app\service\payment\order\PayOrderChannelDispatchService;
use app\service\payment\order\PayOrderLifecycleService;
use app\service\payment\order\PayOrderReuseService;
use app\service\system\config\SystemConfigRuntimeService;
use support\Cache;
use support\Container;
use support\Db;
use support\Request;

/** Counts the real local receipt-plugin dispatch; no Alipay network request is made. */
class CountedReuseDispatch extends PayOrderChannelDispatchService
{
    public int $calls = 0;
    public bool $unknown = false;
    public function __construct(private PayOrderChannelDispatchService $delegate) {}
    public function dispatch(PayOrder $payOrder, BizOrder $bizOrder, PaymentChannel $channel, Merchant $merchant): array
    {
        ++$this->calls;
        check(Db::connection()->transactionLevel() === 0, '复用回归：插件派发仍在事务外');
        if ($this->unknown) throw new PaymentUncertainException('test-only unknown outcome');
        return $this->delegate->dispatch($payOrder, $bizOrder, $channel, $merchant);
    }
}

$dispatch = new CountedReuseDispatch(Container::get(PayOrderChannelDispatchService::class));
$service = routingAttempts([PayOrderChannelDispatchService::class => $dispatch]);
$cashier = Container::get(CashierService::class);
$request = new Request("GET / HTTP/1.1\r\nHost: localhost\r\nUser-Agent: Mozilla/5.0\r\n\r\n");
$f = routingFixture(1, 1, self: true);
routingOccupy($f['channels'][0]);
$input = routingInput($f);
$input['ext_json'] = ['_protocol_version' => 'v1', '_submit_type' => 'page', 'merchant' => ['param' => 'same-business']];
$first = $service->preparePayAttempt($input);
$pay = $first['pay_order']->fresh(); $biz = $first['biz_order']->fresh();
$before = routingCounts(); $paySnapshot = $pay->getAttributes(); $bizSnapshot = $biz->getAttributes();
check($first['created'] && !$first['reused'] && (int) $pay->pay_amount === 10001, '首请求按原规则创建并分配一分偏移');
for ($i = 0; $i < 4; ++$i) {
    $again = $input;
    $again['client_ip'] = '192.0.2.' . ($i + 1);
    $again['device'] = 'mobile';
    $again['ext_json']['_submit_type'] = 'api';
    $result = $service->preparePayAttempt($again);
    check($result['reused'] && !$result['created'] && $result['pay_order']->pay_no === $pay->pay_no
        && $result['payment_result']['presentation'] === $pay->ext_json['presentation'], '重复请求保持原支付号与付款资料 ' . $i);
}
check($dispatch->calls === 1 && routingCounts() === $before, '重复请求只有一次插件派发，不增加支付单、冻结或流水');
check($pay->fresh()->getAttributes() === $paySnapshot && $biz->fresh()->getAttributes() === $bizSnapshot,
    '复用不写订单：IP、设备、门店、备注、金额、尝试次数、有效期、更新时间全部不变');
$confirmed = $cashier->confirm(['biz_no' => $biz->biz_no, 'type' => 'alipay'], $request);
check($confirmed['trade_no'] === $pay->pay_no && routingCounts() === $before, '收银台相同方式重复确认恢复原单');
$cashierInput = $input; unset($cashierInput['pay_type_id']);
check(str_ends_with($service->prepareCashierBizOrder($cashierInput)['cashier_url'], '/payment/' . $pay->pay_no), '无 type 的入口恢复原单支付页');

foreach (['pay_amount' => 10001, 'subject' => 'different', 'body' => 'different',
    'notify_url' => 'https://changed.invalid/notify', 'return_url' => 'https://changed.invalid/return'] as $field => $value) {
    $changed = $input; $changed[$field] = $value;
    $error = routingRejects(fn () => $service->preparePayAttempt($changed),
        $field === 'pay_amount' ? ValidationException::class : ConflictException::class, '拒绝同号修改 ' . $field);
    check(!isset($error->getData()['pay_no']), '冲突异常不会携带可被入口误用的 pay_no：' . $field);
}
$changed = $input; $changed['ext_json']['merchant']['param'] = 'different';
routingRejects(fn () => $service->preparePayAttempt($changed), ConflictException::class, '拒绝修改商户业务扩展字段');
$otherType = PaymentType::firstOrCreate(['code' => 'wxpay'], ['name' => '微信', 'status' => 1]);
$changed = $input; $changed['pay_type_id'] = (int) $otherType->id;
routingRejects(fn () => $service->preparePayAttempt($changed), ConflictException::class, '不把不同支付方式静默解释为继续支付宝');
$changed = $input; $changed['channel_id'] = (int) $pay->channel_id + 10000;
routingRejects(fn () => $service->preparePayAttempt($changed), ConflictException::class, '不忽略明确指定的冲突通道');
$changed = $input; $changed['ext_json']['payment'] = ['buyer_id' => 'different-identity'];
routingRejects(fn () => $service->preparePayAttempt($changed), ConflictException::class, '显式付款身份不能替换原单身份');
check(routingCounts() === $before && $dispatch->calls === 1, '所有冲突均无资金和插件副作用');

$f['channels'][0]->status = 0; $f['channels'][0]->save();
check($service->preparePayAttempt($input)['pay_order']->pay_no === $pay->pay_no, '已有原单不依赖重新选路或当前金额空闲');
$f['channels'][0]->status = 1; $f['channels'][0]->save();

// Explicitly unknown result: committed order remains the only owner of dispatch.
$fUnknown = routingFixture(1, 0, self: true); $unknownInput = routingInput($fUnknown);
$unknown = new CountedReuseDispatch(Container::get(PayOrderChannelDispatchService::class)); $unknown->unknown = true;
$unknownService = routingAttempts([PayOrderChannelDispatchService::class => $unknown]);
routingRejects(fn () => $unknownService->preparePayAttempt($unknownInput), PaymentUncertainException::class, '首次未知结果保留已提交支付单');
$unknownBefore = routingCounts();
$waiting = $unknownService->preparePayAttempt($unknownInput);
check($unknown->calls === 1 && $waiting['reused'] && routingCounts() === $unknownBefore, '未知结果重入绝不补发或换通道');
check($waiting['payment_result']['presentation']['pay_page'] === 'jump'
    && str_ends_with($waiting['pay_params']['url'], '/payment/' . $waiting['pay_order']->pay_no), '资料未就绪返回本站承接地址');
$detail = $cashier->payOrderDetail((string) $waiting['pay_order']->pay_no);
check($detail['presentation_pending'] && $detail['presentation']['pay_params']['_page'] === 'paymentPending', '等待页暴露只读资料等待标记');
$waitingPay = $waiting['pay_order']->fresh();
$ext = $waitingPay->ext_json; $ext['presentation'] = ['pay_page' => 'qrcode', 'pay_params' => ['qrcode' => 'test-only-late-qr']];
$waitingPay->ext_json = $ext; $waitingPay->save();
$detail = $cashier->payOrderDetail((string) $waitingPay->pay_no);
check($detail['order']['status'] === 1 && !$detail['presentation_pending'] && $detail['presentation']['pay_page'] === 'qrcode',
    '资料就绪但订单仍支付中时，等待标记也会改变');

// Expiry is a display restriction, not a synthetic successful close or an extension.
$waitingPay->expire_at = date('Y-m-d H:i:s', time() - 1); $waitingPay->save();
$expiredSnapshot = $waitingPay->fresh()->getAttributes();
$expired = $unknownService->preparePayAttempt($unknownInput);
$detail = $cashier->payOrderDetail((string) $waitingPay->pay_no);
check($expired['pay_params']['url'] === $waiting['pay_params']['url'] && $detail['presentation']['pay_page'] === 'error'
    && !isset($detail['presentation']['pay_params']['qrcode']), '实际有效期已到也不下发旧二维码');
check($waitingPay->fresh()->getAttributes() === $expiredSnapshot && $unknown->calls === 1, '过期重入不延时、不释放资金、不伪造终态');

// Historical terminal-state fixtures cover the lifecycle's cleared active pointer.
foreach ([2, 4, 5] as $status) {
    $tf = routingFixture(1); $ti = routingInput($tf); $t = routingAttempts()->preparePayAttempt($ti);
    $tp = $t['pay_order']; $tb = $t['biz_order'];
    $tp->status = $status; $tp->save(); $tb->status = $status; $tb->active_pay_no = null; $tb->save();
    $counts = routingCounts();
    $r = routingAttempts()->preparePayAttempt($ti);
    $d = $cashier->payOrderDetail((string) $tp->pay_no);
    check($r['reused'] && $r['pay_order']->pay_no === $tp->pay_no && routingCounts() === $counts,
        '终态从历史恢复，不新建，状态 ' . $status);
    check(!isset($d['presentation']['pay_params']['qrcode']) && !isset($d['presentation']['pay_params']['url']),
        '终态页面不返回旧付款材料，状态 ' . $status);
    if ($status === 2) {
        $attrs = $tp->getAttributes(); unset($attrs['id']);
        $attrs['pay_no'] = 'LATE-' . bin2hex(random_bytes(10));
        $attrs['channel_request_no'] = $attrs['pay_no']; $attrs['attempt_no'] = 2; $attrs['status'] = 1;
        PayOrder::create($attrs);
        check(routingAttempts()->preparePayAttempt($ti)['pay_order']->pay_no === $tp->pay_no,
            '晚到成功选择真实成功单，不能把更新的未付尝试冒充成功');
    }
}

// Invalid references and cross-merchant references fail closed.
$originalActive = $biz->active_pay_no;
foreach (['missing-pay-reference', (string) $waitingPay->pay_no] as $reference) {
    $biz->active_pay_no = $reference; $biz->save(); $counts = routingCounts();
    routingRejects(fn () => $service->preparePayAttempt($input), BusinessStateException::class, '损坏或跨业务支付引用拒绝自动重建');
    check(routingCounts() === $counts, '坏引用不产生新支付或资金流水');
}
$biz->active_pay_no = $originalActive; $biz->save();

// Failure retries still use the original switches and limits, never the resume path.
$defaults = Container::get(SystemConfigRuntimeService::class)->all();
try {
    $tf = routingFixture(1); $ti = routingInput($tf); $t = routingAttempts()->preparePayAttempt($ti);
    Container::get(PayOrderLifecycleService::class)->markPayFailed((string) $t['pay_order']->pay_no);
    Cache::set('system_config:all', array_replace($defaults, ['pay_order_failed_retry_enabled' => '0']));
    $counts = routingCounts();
    routingRejects(fn () => routingAttempts()->preparePayAttempt($ti), BusinessStateException::class, '明确失败仍遵守禁止重试开关');
    check(routingCounts() === $counts, '禁重试不产生新单');
    Cache::set('system_config:all', array_replace($defaults, ['pay_order_failed_retry_enabled' => '1',
        'pay_order_attempt_limit_enabled' => '1', 'pay_order_attempt_limit' => '1']));
    routingRejects(fn () => routingAttempts()->preparePayAttempt($ti), BusinessStateException::class, '明确失败仍遵守尝试次数上限');
    Cache::set('system_config:all', array_replace($defaults, ['pay_order_failed_retry_enabled' => '1',
        'pay_order_attempt_limit_enabled' => '1', 'pay_order_attempt_limit' => '5']));
    $retry = routingAttempts()->preparePayAttempt($ti);
    check($retry['created'] && $retry['pay_order']->pay_no !== $t['pay_order']->pay_no
        && (int) $retry['biz_order']->attempt_count === 2, '只有明确允许的失败重试才新建第二次尝试');
} finally { Cache::set('system_config:all', $defaults); }

function reuseSigned(array $payload, string $key, string $algorithm): array
{
    unset($payload['sign'], $payload['sign_type']);
    $sign = Container::get(EpaySignerManager::class)->sign($payload, $algorithm, $key);
    return $payload + ['sign' => $sign, 'sign_type' => $algorithm];
}
function reuseLocation(\support\Response $response): string
{
    preg_match('/^Location:\s*([^\r\n]+)/mi', (string) $response, $match);
    return $match[1] ?? '';
}
function reuseProtocolPayload(array $fixture, string $order): array
{
    return ['pid' => (string) $fixture['merchant']->id, 'type' => 'alipay', 'out_trade_no' => $order,
        'money' => '100.00', 'name' => '重复支付协议测试', 'notify_url' => 'https://merchant.example.invalid/notify',
        'return_url' => 'https://merchant.example.invalid/return', 'clientip' => '127.0.0.1', 'device' => 'pc'];
}

// Real V2 RSA request verification and newly signed response. Keys exist only in this test process.
$keyResource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($keyResource === false || !openssl_pkey_export($keyResource, $privateKey)) throw new RuntimeException('Cannot create test RSA key');
$publicKey = openssl_pkey_get_details($keyResource)['key'];
$epayConfig = (array) config('epay');
$temp = sys_get_temp_dir() . '/mpay-reuse-config-' . bin2hex(random_bytes(6)); mkdir($temp, 0700);
try {
    // Webman only loads configuration directories containing app.php.
    file_put_contents($temp . '/app.php', '<?php return [];');
    $testConfig = $epayConfig; $testConfig['v2']['platform_private_key'] = $privateKey;
    file_put_contents($temp . '/epay.php', '<?php return ' . var_export($testConfig, true) . ';');
    \Webman\Config::load($temp);
    check(config('epay.v2.platform_private_key') === $privateKey, 'V2 临时签名配置已加载');
    $pf = routingFixture(1);
    MerchantApiCredential::create(['merchant_id' => $pf['merchant']->id, 'api_key' => bin2hex(random_bytes(16)),
        'merchant_public_key' => $publicKey, 'status' => 1]);
    $p2 = Container::get(EpayV2ProtocolService::class);
    $payload = reuseSigned(reuseProtocolPayload($pf, 'V2-' . bin2hex(random_bytes(8)))
        + ['timestamp' => (string) time(), 'method' => 'web'], $privateKey, 'RSA');
    $location = reuseLocation($p2->submit($payload, $request));
    check(str_contains($location, '/payment/PAY') && !str_contains($location, '/entry/'), 'V2 首次 RSA 页面提交成功');
    $counts = routingCounts();
    check(reuseLocation($p2->submit($payload, $request)) === $location, 'V2 页面重入返回同一支付单');
    $api = $p2->create($payload, $request);
    check($api['code'] === 0 && str_ends_with($location, '/payment/' . $api['trade_no']), 'V2 API 复用原 trade_no');
    $signed = $api; unset($signed['sign'], $signed['sign_type']);
    check(Container::get(EpaySignerManager::class)->verify($signed, 'RSA', $api['sign'], $publicKey), 'V2 复用响应按正常流程重新签名');
    $vp = PayOrder::where('pay_no', $api['trade_no'])->firstOrFail();
    $ext = $vp->ext_json; unset($ext['presentation']); $vp->ext_json = $ext; $vp->save();
    $api = $p2->create($payload, $request);
    check($api['code'] === 0 && $api['pay_type'] === 'jump' && $api['pay_info'] === $location,
        'V2 资料尚未就绪时返回合法 jump，不报缺少承接信息');
    $bad = $payload; $bad['sign'] = 'invalid';
    check($p2->create($bad, $request)['code'] === 1, 'V2 原单复用不绕过 RSA 验签');
    $bad = reuseSigned(array_replace($payload, ['timestamp' => (string) (time() - 3600)]), $privateKey, 'RSA');
    check($p2->create($bad, $request)['code'] === 1, 'V2 原单复用不绕过时间戳有效期');
    check(routingCounts() === $counts, 'V2 重入及无效请求均没有新增支付或冻结');

    // Non-web response contract is retained without invoking a charge operation.
    $method = new ReflectionMethod(EpayV2ProtocolService::class, 'buildCreateResponse');
    $vb = BizOrder::where('biz_no', $vp->biz_no)->firstOrFail();
    $ext = $vp->ext_json; $ext['payment'] = ['method' => 'auth_code', 'auth_code' => 'TEST-ONLY'];
    $vp->ext_json = $ext;
    $dto = Container::get(PayOrderReuseService::class)->buildResult($vb, $vp, $pf['merchant']);
    $scan = $method->invoke($p2, $vp, $dto['payment_result']);
    check($scan['pay_type'] === 'scan' && $scan['pay_info']['status'] === 'pending'
        && $scan['pay_info']['paid_amount'] === null, '付款码重入只返回真实待确认摘要，不伪造成功或重复扣款');
    $ext['payment'] = ['method' => 'app']; $vp->ext_json = $ext;
    $dto = Container::get(PayOrderReuseService::class)->buildResult($vb, $vp, $pf['merchant']);
    check($method->invoke($p2, $vp, $dto['payment_result'])['pay_type'] === 'jump', 'APP 资料缺失回本站承接，不读取不存在的 params');
} finally {
    file_put_contents($temp . '/epay.php', '<?php return ' . var_export($epayConfig, true) . ';');
    \Webman\Config::load($temp);
    unlink($temp . '/epay.php'); unlink($temp . '/app.php'); rmdir($temp);
}
echo "PASS: $checks payment reuse, state, security and protocol checks\n";
