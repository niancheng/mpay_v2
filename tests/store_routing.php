<?php

declare(strict_types=1);
require __DIR__ . '/store_routing_helpers.php';

use app\common\constant\RouteConstant as RC;
use app\exception\ReceiptAmountOccupiedException;
use app\exception\PaymentException;
use app\exception\PaymentUncertainException;
use app\exception\ConflictException;
use app\model\payment\PayOrder;
use app\model\payment\BizOrder;
use app\model\payment\PaymentChannel;
use app\model\payment\PaymentPluginConf;
use app\model\payment\PaymentPollGroupChannel;
use app\model\merchant\MerchantAccount;
use app\service\payment\receipt\AlipayStoreReceiptRules as R;
use app\service\payment\runtime\PaymentRouteService;
use app\service\payment\runtime\PaymentPluginManager;
use app\service\payment\order\PayOrderChannelDispatchService;
use support\Container;
use support\Db;
use support\Redis;

$f = routingFixture(3, 2); [$a, $b, $c] = $f['channels'];
routingOccupy($a);
$result = routingAttempts()->preparePayAttempt(routingInput($f));
check((int) $result['pay_order']->channel_id === (int) $b->id && $result['pay_params']['amount'] === '100.00', 'A 可偏移仍优先 B 原金额，完整下单链路成功');
check((int) $result['biz_order']->attempt_count === 1 && PayOrder::where('biz_no', $result['biz_order']->biz_no)->count() === 1, '只创建最终选中门店的支付尝试');
routingOccupy($c);
$offset = routingAttempts()->preparePayAttempt(routingInput($f));
check((int) $offset['pay_order']->channel_id === (int) $a->id && $offset['pay_params']['amount'] === '100.01', '全部原金额占用后才在允许范围内偏移');
check($offset['pay_order']->ext_json['personal_receipt']['original_amount'] === 10000 && $offset['biz_order']->order_amount === 10000, '偏移不改变业务订单原金额');
$meta = $offset['pay_order']->ext_json['personal_receipt'];
Container::get(PaymentPluginManager::class)->createByChannel($a)->pay(['pay_no' => $offset['pay_order']->pay_no, 'amount' => 10000, 'pay_type_code' => 'alipay']);
check($offset['pay_order']->fresh()->ext_json['personal_receipt'] === $meta, '刷新二维码保留金额、门店、二维码与有效期');

// Route previews and cashier pay-type previews must not mutate even the cursor TTL.
$f = routingFixture(3, 0, RC::ROUTE_MODE_ORDER); [$a, $b, $c] = $f['channels'];
$key = sprintf('payment:route:round_robin:merchant:%d:pay_type:%d', $f['merchant']->id, $f['type_id']);
Redis::del($key); $counts = routingCounts();
routingSnapshot($f); routingSnapshot($f);
Container::get(PaymentRouteService::class)->previewAvailablePayTypes((int) $f['group']->id, 10000, ['merchant_id' => $f['merchant']->id]);
check(!Redis::exists($key) && routingCounts() === $counts, '两类预览不创建订单、占用或轮询游标');
Redis::setex($key, 123, 1); routingOccupy($b);
$preview = routingSnapshot($f);
check((int) $preview['selected_channel']['channel_id'] === (int) $b->id && (int) Redis::get($key) === 1 && Redis::ttl($key) <= 123, '旧顺序预览不感知占用，不推进或续期游标');
routingRejects(fn () => routingAttempts()->preparePayAttempt(routingInput($f)), ReceiptAmountOccupiedException::class, '旧顺序轮到占用的 B 时拒绝，不自动换 C');
check((int) Redis::get($key) === 2, '旧顺序正式请求只推进一次游标');
$result = routingAttempts()->preparePayAttempt(routingInput($f));
check((int) $result['pay_order']->channel_id === (int) $c->id && (int) Redis::get($key) === 3, '下一笔旧顺序请求才轮到 C');

$f = routingFixture(2, 1, RC::ROUTE_MODE_WEIGHTED); routingOccupy($f['channels'][0]);
for ($i = 0; $i < 12; ++$i) {
    $route = routingSnapshot($f);
    check(!isset($route['receipt_attempt_phases']) && count($route['candidates']) === 2, '旧权重保留完整合格候选，不进入金额优先分支');
}

// Same config shares amounts with all associated channels, even outside this poll group and disabled.
$f = routingFixture(); [$a, $b] = $f['channels'];
$outside = PaymentChannel::create(array_replace($a->getAttributes(), ['id' => null, 'name' => 'outside-route', 'merchant_id' => 999999, 'channel_mode' => 1]))->fresh();
routingOccupy($outside); $outside->status = 0; $outside->save();
$result = routingAttempts()->preparePayAttempt(routingInput($f));
check((int) $result['pay_order']->channel_id === (int) $b->id, '跨商户、跨轮询组和已停用关联通道的有效占用均可感知');
check(!in_array((int) $outside->id, array_column($result['route']['candidates'], 'channel_id'), true), '不能通过换店分配其他商户的私有通道');

$f = routingFixture(); [$a, $b] = $f['channels'];
routingOccupy($a, prepare: false);
check((int) routingSnapshot($f)['selected_channel']['channel_id'] === (int) $a->id, '未分配识别快照的支付单不算占用');
$active = routingOccupy($a);
foreach ([2, 3, 4, 5] as $status) {
    $active->status = $status; $active->save();
    check((int) routingSnapshot($f)['selected_channel']['channel_id'] === (int) $a->id, '已结束支付单释放占用，状态 ' . $status);
}
$active->status = 1; $active->expire_at = R::date(time() - 1); $active->save();
check((int) routingSnapshot($f)['selected_channel']['channel_id'] === (int) $a->id, '识别窗口过期即不再占用，不必等待清理任务');

$f = routingFixture(); foreach ($f['channels'] as $channel) routingOccupy($channel);
$counts = routingCounts();
$error = routingRejects(fn () => routingAttempts()->preparePayAttempt(routingInput($f)), ReceiptAmountOccupiedException::class, '所有零偏移门店占用时明确拒绝');
check($error->getData()['reason_code'] === 'receipt_amount_occupied' && routingCounts() === $counts, '预检失败没有业务单、支付单、冻结或流水副作用');
$preview = routingSnapshot($f);
check(!$preview['available'] && count($preview['rejected_candidates']) === 2 && $preview['reason_code'] === 'receipt_amount_occupied', '路由预览可展示每家门店的金额拒绝原因');
check(Container::get(PaymentRouteService::class)->previewAvailablePayTypes((int) $f['group']->id, 10000, ['merchant_id' => $f['merchant']->id]) === [], '不可用支付方式预览安全返回空列表');

// Pre-check -> concurrent winner -> transactional re-check. More than five skips must not exhaust attempts.
$f = routingFixture(6, 0, self: true); $snapshot = routingSnapshot($f); $input = routingInput($f);
$service = routingAttempts([PaymentRouteService::class => new FrozenReceiptRoute($snapshot)]);
$biz = $service->prepareCashierBizOrder($input)['biz_order'];
for ($i = 0; $i < 5; ++$i) routingOccupy($f['channels'][$i]);
$before = routingCounts();
$result = $service->preparePayAttempt($input);
check((int) $result['pay_order']->channel_id === (int) $f['channels'][5]->id && (int) $result['biz_order']->attempt_count === 1, '连续五次并发冲突回滚后仍能使用第六家，尝试次数仍为一');
check(PayOrder::where('biz_no', $biz->biz_no)->count() === 1, '并发抢占不会残留未展示的失败支付单');
$account = MerchantAccount::where('merchant_id', $f['merchant']->id)->first();
$after = routingCounts();
check((int) $account->available_balance === 999500 && (int) $account->frozen_balance === 500 && $after[2] - $before[2] === 1 && $after[3] - $before[3] === 1, '多次冲突回滚只保留最终一份服务费冻结和资金流水');
$meta = $result['pay_order']->ext_json['personal_receipt'];
$reused = $service->preparePayAttempt($input);
check($reused['reused'] && $reused['pay_order']->pay_no === $result['pay_order']->pay_no, '重复业务请求恢复原单，不重新分配门店');
check(PayOrder::where('biz_no', $biz->biz_no)->count() === 1 && $result['pay_order']->fresh()->ext_json['personal_receipt'] === $meta, '重复请求没有额外冻结或更改旧快照');

$f = routingFixture(3, 0, self: true); $snapshot = routingSnapshot($f); $input = routingInput($f);
$service = routingAttempts([PaymentRouteService::class => new FrozenReceiptRoute($snapshot)]);
$biz = $service->prepareCashierBizOrder($input)['biz_order'];
foreach ($f['channels'] as $channel) routingOccupy($channel);
$before = routingCounts();
routingRejects(fn () => $service->preparePayAttempt($input), ReceiptAmountOccupiedException::class, '所有候选预检后被抢占，最终拒绝');
$account = MerchantAccount::where('merchant_id', $f['merchant']->id)->first();
check(routingCounts() === $before && (int) $account->available_balance === 1000000 && (int) $account->frozen_balance === 0, '最终失败时所有候选的支付单、资金流水和冻结完整回滚');
check((int) $biz->fresh()->attempt_count === 0 && $biz->fresh()->active_pay_no === '', '最终失败不增加已有业务单尝试次数或活跃支付单引用');

$f = routingFixture(2, 1); $snapshot = routingSnapshot($f);
foreach ($f['channels'] as $channel) routingOccupy($channel);
$result = routingAttempts([PaymentRouteService::class => new FrozenReceiptRoute($snapshot)])->preparePayAttempt(routingInput($f));
check($result['pay_params']['amount'] === '100.01' && (int) $result['biz_order']->attempt_count === 1, '预检原金额空闲但随后全被抢占，仍进入有界偏移兜底');

// Specified-channel tests must never silently select a different store.
$f = routingFixture(2, 1); [$a, $b] = $f['channels']; routingOccupy($a);
$result = routingAttempts()->preparePayAttemptByChannel(routingInput($f), $a);
check((int) $result['pay_order']->channel_id === (int) $a->id && $result['pay_params']['amount'] === '100.01', '指定通道只在本门店偏移，不切换空闲的 B');
$counts = routingCounts();
routingRejects(fn () => routingAttempts()->preparePayAttemptByChannel(routingInput($f), $a), ReceiptAmountOccupiedException::class, '指定通道偏移耗尽后拒绝而非换店');
check(routingCounts() === $counts, '指定通道冲突同样没有失败尝试残留');

// Reserved metadata supplied by a caller cannot bypass amount allocation.
$f = routingFixture(1, 1); routingOccupy($f['channels'][0]);
$input = routingInput($f); $input['ext_json'] = ['personal_receipt' => ['receipt_amount' => 1], 'receipt_watcher' => ['store_no' => 'forged']];
$result = routingAttempts()->preparePayAttempt($input);
check($result['pay_order']->ext_json['personal_receipt']['receipt_amount'] === 10001 && $result['pay_order']->ext_json['receipt_watcher']['store_no'] !== 'forged', '调用方无法注入识别快照绕过占用');

// Post-commit exceptions, even an identically named amount error, cannot be caught by the skip loop.
class LateReceiptFailure extends PayOrderChannelDispatchService
{
    public int $calls = 0;
    public function __construct(private Throwable $error) {}
    public function dispatch(PayOrder $payOrder, BizOrder $bizOrder, PaymentChannel $channel, \app\model\merchant\Merchant $merchant): array
    {
        ++$this->calls;
        check(Db::connection()->transactionLevel() === 0, '插件派发位于金额占用事务之外');
        throw $this->error;
    }
}
foreach ([new ReceiptAmountOccupiedException('synthetic late failure'), new PaymentUncertainException('synthetic unknown outcome')] as $error) {
    $f = routingFixture(); $dispatch = new LateReceiptFailure($error); $input = routingInput($f);
    routingRejects(fn () => routingAttempts([PayOrderChannelDispatchService::class => $dispatch])->preparePayAttempt($input), $error::class, '派发后错误不得重新换店');
    $biz = BizOrder::where('merchant_id', $f['merchant']->id)->where('merchant_order_no', $input['merchant_order_no'])->firstOrFail();
    check($dispatch->calls === 1 && PayOrder::where('biz_no', $biz->biz_no)->count() === 1 && (int) $biz->attempt_count === 1, '未知或迟到错误保留原支付尝试，不生成第二单');
}

$f = routingFixture(); $snapshot = routingSnapshot($f); $f['channels'][0]->status = 0; $f['channels'][0]->save();
$counts = routingCounts();
$error = routingRejects(fn () => routingAttempts([PaymentRouteService::class => new FrozenReceiptRoute($snapshot)])->preparePayAttempt(routingInput($f)), PaymentException::class, '预检后通道被禁用应按配置错误终止');
check(!$error instanceof ReceiptAmountOccupiedException && routingCounts() === $counts, '配置变化不能伪装为金额占用继续换店');

// Offset payment completes using the unchanged receipt callback contract.
$f = routingFixture(1, 1); $a = $f['channels'][0]; routingOccupy($a);
$result = routingAttempts()->preparePayAttempt(routingInput($f)); $order = $result['pay_order'];
$config = PaymentPluginConf::findOrFail($a->api_config_id)->config;
$record = testFlow('ROUTE-CALLBACK-' . bin2hex(random_bytes(5)), ['bill_user_id' => $config['bill_user_id'], 'store_no' => $config['store_no'],
    'candidate_channel_id' => (int) $a->id, 'candidate_pay_no' => (string) $order->pay_no, 'price' => '100.01', 'created_at' => time(), 'paid_at' => time()]);
Container::get(\app\service\payment\order\PayOrderCallbackService::class)->handleChannelNotifyPayload((int) $a->id, ['record' => $record]);
check((int) $order->fresh()->status === 2 && (int) $order->fresh()->pay_amount === 10000 && (int) $result['biz_order']->fresh()->paid_amount === 10000, '完整下单后用原监听协议回调成功，业务结算仍为原价');

$callbackCounts = routingCounts();
Container::get(\app\service\payment\order\PayOrderCallbackService::class)->handleChannelNotifyPayload((int) $a->id, ['record' => $record]);
check(routingCounts() === $callbackCounts, '重复到账通知不重复生成资金流水');
$paidInput = routingInput($f, (string) $result['biz_order']->merchant_order_no);
$paidResume = routingAttempts()->preparePayAttempt($paidInput);
check($paidResume['reused'] && $paidResume['pay_order']->pay_no === $order->pay_no && routingCounts() === $callbackCounts,
    '真实本地到账链路成功后，同号请求恢复结果，不重复收款或通知');

// No-store routes still use the old candidate contract; no receipt pre-check queries are needed.
$f = routingFixture(mode: RC::ROUTE_MODE_FIRST_AVAILABLE); $channel = $f['channels'][0];
$legacy = PaymentPluginConf::create(['plugin_code' => R::LEGACY, 'config' => storeConfig(changes: ['bill_user_id' => '2088999999999998'])]);
$channel->plugin_code = R::LEGACY; $channel->api_config_id = $legacy->id; $channel->save();
$f['channels'][1]->status = 0; $f['channels'][1]->save();
$route = routingSnapshot($f);
check((int) $route['selected_channel']['channel_id'] === (int) $channel->id && !isset($route['receipt_attempt_phases']), '其他插件不进入门店金额分配两阶段流程');
require __DIR__ . '/amount_priority_routing.php';
echo "PASS: $checks full-route MySQL checks (synthetic payment evidence, no live Alipay)\n";