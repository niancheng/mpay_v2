<?php

declare(strict_types=1);
require_once __DIR__ . '/store_routing_helpers.php';

use app\common\constant\RouteConstant as RC;
use app\exception\BusinessStateException;
use app\exception\ReceiptAmountOccupiedException;
use app\exception\ValidationException;
use app\http\admin\validation\PaymentPollGroupValidator;
use app\model\payment\PaymentChannel;
use app\model\payment\PaymentPluginConf;
use app\model\payment\PaymentPollGroupChannel;
use app\service\bootstrap\SystemBootstrapService;
use app\service\merchant\portal\MerchantPortalRouteConfigService;
use app\service\merchant\portal\MerchantPortalRoutePreviewService;
use app\service\payment\config\PaymentPollGroupService;
use app\service\payment\receipt\AlipayStoreReceiptRules as R;
use app\service\payment\runtime\PaymentRouteService;
use support\Container;
use support\Redis;

// Verify the real admin dictionary/validator and merchant settings, not just the enum.
check(array_keys(RC::routeModeMap()) === [0, 1, 2, 3], '旧策略编号保持不变，新增独立策略 3');
$dict = Container::get(SystemBootstrapService::class)->getDictItems('route_mode');
check(array_column($dict['list'], 'name', 'value') === RC::routeModeMap(), '管理后台策略字典与枚举一致，无需修改前端构建产物');
$f = routingFixture();
$payload = ['group_name' => 'amount-priority-' . bin2hex(random_bytes(5)), 'pay_type_id' => $f['type_id'], 'route_mode' => 3];
$data = PaymentPollGroupValidator::make($payload)->withScene('store')->withException(ValidationException::class)->validate();
$poll = Container::get(PaymentPollGroupService::class)->create($data);
check((int) $poll->route_mode === 3, '后台新增轮询组可校验并保存金额优先策略');
$data = PaymentPollGroupValidator::make(array_replace($payload, ['id' => $poll->id, 'route_mode' => 2]))
    ->withScene('update')->withException(ValidationException::class)->validate();
Container::get(PaymentPollGroupService::class)->update((int) $poll->id, $data);
check((int) $poll->fresh()->route_mode === 2, '后台仍能切回旧策略');
routingRejects(fn () => PaymentPollGroupValidator::make(array_replace($payload, ['route_mode' => 4]))
    ->withScene('store')->withException(ValidationException::class)->validate(), ValidationException::class, '未知策略编号仍被拒绝');
$portal = Container::get(MerchantPortalRouteConfigService::class);
$settings = $portal->settings((int) $f['merchant']->id);
check(array_column($settings['route_modes'], 'label', 'value') === RC::routeModeMap(), '商户配置界面也能选择新策略');

// Start from sort_no / id on EVERY payment, regardless of cursor/default/weight.
$f = routingFixture(3, 2); [$a, $b, $c] = $f['channels'];
PaymentPollGroupChannel::where('poll_group_id', $f['poll']->id)->where('channel_id', $a->id)->update(['sort_no' => 20, 'is_default' => 1, 'weight' => 999]);
PaymentPollGroupChannel::where('poll_group_id', $f['poll']->id)->where('channel_id', $b->id)->update(['sort_no' => 10, 'is_default' => 0]);
PaymentPollGroupChannel::where('poll_group_id', $f['poll']->id)->where('channel_id', $c->id)->update(['sort_no' => 10, 'is_default' => 0]);
$key = sprintf('payment:route:round_robin:merchant:%d:pay_type:%d', $f['merchant']->id, $f['type_id']);
Redis::setex($key, 120, 77); $counts = routingCounts();
$route = routingSnapshot($f);
check(array_column($route['receipt_attempt_phases'][0]['candidates'], 'channel_id') === [(int) $b->id, (int) $c->id, (int) $a->id], '新策略只按排序号和 ID，忽略默认标记与权重');
check((int) Redis::get($key) === 77 && Redis::ttl($key) <= 120 && routingCounts() === $counts, '新策略预览不推进游标、不延长 TTL、不创建订单');
$result = routingAttempts()->preparePayAttempt(routingInput($f, amount: 10100));
$next = routingAttempts()->preparePayAttempt(routingInput($f, amount: 10200));
check((int) $result['pay_order']->channel_id === (int) $b->id && (int) $next['pay_order']->channel_id === (int) $b->id, '不同金额连续下单都从排序第一家开始，不轮换起点');
check((int) Redis::get($key) === 77 && Redis::ttl($key) <= 120, '新策略正式下单也不读取推进轮询位置');
routingOccupy($b);
$result = routingAttempts()->preparePayAttempt(routingInput($f));
check((int) $result['pay_order']->channel_id === (int) $c->id && $result['pay_params']['amount'] === '100.00', '第一家原金额占用后按排序尝试第二家，不使用第一家偏移');

// Fallback restarts from the first authorized store; it is NOT global minimum-offset selection.
$f = routingFixture(2, 5); [$a, $b] = $f['channels'];
for ($amount = 10000; $amount < 10005; ++$amount) routingOccupy($a, $amount);
routingOccupy($b);
$result = routingAttempts()->preparePayAttempt(routingInput($f));
check((int) $result['pay_order']->channel_id === (int) $a->id && $result['pay_params']['amount'] === '100.05', '兜底按排序选 A 加五分，不为更小偏移改选 B 加一分');
$f = routingFixture(2, 1); [$a, $b] = $f['channels'];
routingOccupy($a); routingOccupy($a, 10001); routingOccupy($b);
$result = routingAttempts()->preparePayAttempt(routingInput($f));
check((int) $result['pay_order']->channel_id === (int) $b->id && $result['pay_params']['amount'] === '100.01', '第一家全部偏移用尽，第二轮顺序使用下一家');

// A store ruled out by the advisory probe can become usable before the second pass.
$f = routingFixture(2, 0); [$a, $b] = $f['channels'];
$occupied = routingOccupy($a); $snapshot = routingSnapshot($f);
check(array_column($snapshot['receipt_attempt_phases'][1]['candidates'], 'channel_id') === [(int) $a->id, (int) $b->id], '第二轮保留原始合格列表，包括零偏移及预检已满通道');
routingOccupy($b); $occupied->status = 4; $occupied->save();
$result = routingAttempts([PaymentRouteService::class => new FrozenReceiptRoute($snapshot)])->preparePayAttempt(routingInput($f));
check((int) $result['pay_order']->channel_id === (int) $a->id && $result['pay_params']['amount'] === '100.00', '第一轮 B 被抢占后，第二轮重新从 A 开始并使用刚释放的原金额');
$f = routingFixture(1, 0); $occupied = routingOccupy($f['channels'][0]);
$snapshot = routingSnapshot($f, preview: false); $occupied->status = 4; $occupied->save();
$result = routingAttempts([PaymentRouteService::class => new FrozenReceiptRoute($snapshot)])->preparePayAttempt(routingInput($f));
check($result['pay_params']['amount'] === '100.00', '预检所有金额已满仍由事务内复查作最终裁决');

// Multiple channel entries for one configuration never create independent amount capacity.
$f = routingFixture(); [$a, $b] = $f['channels'];
$clone = PaymentChannel::create(array_replace($a->getAttributes(), ['id' => null, 'name' => 'shared-config']))->fresh();
PaymentPollGroupChannel::create(['poll_group_id' => $f['poll']->id, 'channel_id' => $clone->id, 'sort_no' => 0, 'status' => 1]);
routingOccupy($a);
$result = routingAttempts()->preparePayAttempt(routingInput($f));
check((int) $result['pay_order']->channel_id === (int) $b->id, '同一门店重复通道共享占用，不增加原金额容量');

// New strategy explicitly excludes non-store plugins; old routes keep supporting them.
$f = routingFixture(); [$a, $b] = $f['channels'];
$legacy = PaymentPluginConf::create(['plugin_code' => R::LEGACY, 'config' => storeConfig(changes: ['bill_user_id' => '2088999999999997'])]);
$a->plugin_code = R::LEGACY; $a->api_config_id = $legacy->id; $a->save();
$route = routingSnapshot($f);
check((int) $route['selected_channel']['channel_id'] === (int) $b->id && count($route['rejected_candidates']) === 1
    && str_contains($route['rejected_candidates'][0]['reject_reason_text'], '仅支持'), '混合组中普通插件被明确排除，不冒充原金额可用');
$result = routingAttempts()->preparePayAttempt(routingInput($f));
check((int) $result['pay_order']->channel_id === (int) $b->id, '新策略只对门店插件下单');
$b->status = 0; $b->save(); $counts = routingCounts();
$route = routingSnapshot($f);
check(!$route['available'] && $route['reason_code'] === 'amount_priority_plugin_unsupported', '无支持插件时预览给出明确原因');
routingRejects(fn () => routingAttempts()->preparePayAttempt(routingInput($f)), BusinessStateException::class, '无门店候选时不退回普通插件');
check(routingCounts() === $counts, '不支持的路由没有订单或资金副作用');
$f['poll']->route_mode = RC::ROUTE_MODE_FIRST_AVAILABLE; $f['poll']->save();
check((int) routingSnapshot($f)['selected_channel']['channel_id'] === (int) $a->id, '切回旧策略后普通插件仍可参与原路由');

// No hidden amount-aware selection/fallback may remain in any of the three legacy modes.
foreach ([RC::ROUTE_MODE_ORDER, RC::ROUTE_MODE_WEIGHTED, RC::ROUTE_MODE_FIRST_AVAILABLE] as $mode) {
    $f = routingFixture(2, 1, $mode); [$a, $b] = $f['channels']; routingOccupy($a);
    $snapshot = routingSnapshot($f);
    check(!isset($snapshot['receipt_attempt_phases']) && count($snapshot['candidates']) === 2, '旧策略 ' . $mode . ' 不执行门店金额预检');
    // Freeze a legitimate candidate selection to exercise dispatch deterministically for weighted mode too.
    $snapshot['selected_channel'] = $snapshot['candidates'][0];
    $service = routingAttempts([PaymentRouteService::class => new FrozenReceiptRoute($snapshot)]);
    $result = $service->preparePayAttempt(routingInput($f));
    check((int) $result['pay_order']->channel_id === (int) $a->id && $result['pay_params']['amount'] === '100.01', '旧策略 ' . $mode . ' 选定 A 后按插件偏移，不优先空闲 B');
    $counts = routingCounts();
    routingRejects(fn () => $service->preparePayAttempt(routingInput($f)), ReceiptAmountOccupiedException::class, '旧策略 ' . $mode . ' 偏移耗尽后终止，不自动换 B');
    check(routingCounts() === $counts, '旧策略拒绝仍完整回滚，不撤销底层事务安全');
}

// Merchant override remains authoritative, and actual merchant previews are read-only.
$f = routingFixture(2, 1, RC::ROUTE_MODE_ORDER); routingOccupy($f['channels'][0]);
$portal->save((int) $f['merchant']->id, ['items' => [['pay_type_id' => $f['type_id'], 'use_platform_channels' => 1,
    'route_mode' => RC::ROUTE_MODE_AMOUNT_PRIORITY, 'default_channel_id' => 0]]]);
check((int) routingSnapshot($f)['selected_channel']['channel_id'] === (int) $f['channels'][1]->id, '商户新策略覆盖轮询组旧策略并保持原金额');
$portal->save((int) $f['merchant']->id, ['items' => [['pay_type_id' => $f['type_id'], 'use_platform_channels' => 1,
    'route_mode' => RC::ROUTE_MODE_FIRST_AVAILABLE, 'default_channel_id' => $f['channels'][0]->id]]]);
check(!isset(routingSnapshot($f)['receipt_attempt_phases']), '商户切回旧策略后立即停止金额优先');
$f = routingFixture(2, 0, RC::ROUTE_MODE_ORDER);
$key = sprintf('payment:route:round_robin:merchant:%d:pay_type:%d', $f['merchant']->id, $f['type_id']);
Redis::setex($key, 120, 1); $counts = routingCounts();
$previewService = Container::get(MerchantPortalRoutePreviewService::class);
$preview = $previewService->routePreview((int) $f['merchant']->id, $f['type_id'], 10000);
check($preview['available'] && (int) Redis::get($key) === 1 && Redis::ttl($key) <= 120 && routingCounts() === $counts, '实际商户路由预览入口不推进或续期旧轮询游标');
$f = routingFixture(); foreach ($f['channels'] as $channel) routingOccupy($channel);
$preview = $previewService->routePreview((int) $f['merchant']->id, $f['type_id'], 10000);
check(!$preview['available'] && str_contains($preview['reason'], '占用') && $preview['selected_channel'] === null, '实际商户预览正确显示新策略金额用尽，不误报可用');
