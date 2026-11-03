<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use app\common\constant\RouteConstant;
use app\model\merchant\Merchant;
use app\model\merchant\MerchantGroup;
use app\model\merchant\MerchantAccount;
use app\model\payment\PaymentChannel;
use app\model\payment\PaymentPluginConf;
use app\model\payment\PaymentPlugin;
use app\model\payment\PaymentPollGroup;
use app\model\payment\PaymentPollGroupBind;
use app\model\payment\PaymentPollGroupChannel;
use app\model\payment\PaymentType;
use app\model\payment\PayOrder;
use app\service\payment\order\PayOrderAttemptService;
use app\service\payment\runtime\PaymentRouteService;
use app\service\payment\runtime\PaymentPluginManager;
use app\service\payment\receipt\AlipayStoreReceiptRules as R;
use support\Container;
use support\Db;

$db = (string) env('DB_DATABASE', '');
if (!preg_match('/^mpay_test_[a-z0-9_]+$/D', $db) || env('MPAY_TEST_DATABASE') !== $db) throw new RuntimeException('Dedicated MPAY_TEST_DATABASE required');

function routingFixture(int $stores = 2, int $offset = 0, int $mode = RouteConstant::ROUTE_MODE_FIRST_AVAILABLE, bool $self = false): array
{
    $seed = bin2hex(random_bytes(5));
    $uid = '2088' . str_pad((string) random_int(100000, 999999999999), 12, '0', STR_PAD_LEFT);
    $typeId = (int) PaymentType::where('code', 'alipay')->value('id');
    if (!$typeId) throw new RuntimeException('Run store_integration.php before the routing tests');
    // Migrations register only the new store plugin; the empty test database has no legacy seed data.
    // Use the original plugin's own metadata so non-store route regression tests exercise a valid route.
    $legacy = Container::get(\app\common\payment\AlipayBillReceiptPayment::class);
    PaymentPlugin::firstOrCreate(['code' => $legacy->getCode()], [
        'name' => $legacy->getName(), 'class_name' => 'AlipayBillReceiptPayment',
        'plugin_type' => $legacy->getPluginType(), 'pay_types' => $legacy->getEnabledPayTypes(), 'status' => 1,
    ]);
    $group = MerchantGroup::create(['group_name' => 'route-' . $seed, 'status' => 1]);
    $merchant = Merchant::create(['merchant_no' => 'RT-' . $seed, 'merchant_name' => '路由测试', 'group_id' => $group->id, 'status' => 1, 'pay_status' => 1]);
    MerchantAccount::create(['merchant_id' => $merchant->id, 'available_balance' => 1000000, 'frozen_balance' => 0]);
    $poll = PaymentPollGroup::create(['group_name' => 'route-' . $seed, 'pay_type_id' => $typeId, 'route_mode' => $mode, 'status' => 1]);
    PaymentPollGroupBind::create(['merchant_group_id' => $group->id, 'pay_type_id' => $typeId, 'poll_group_id' => $poll->id, 'status' => 1]);
    $channels = [];
    for ($i = 0; $i < $stores; ++$i) {
        $config = PaymentPluginConf::create(['merchant_id' => $self ? $merchant->id : 0, 'plugin_code' => R::PLUGIN,
            'config' => storeConfig('路由门店-' . $seed . '-' . $i, ['bill_user_id' => $uid, 'amount_offset_max' => $offset])]);
        $channel = PaymentChannel::create(['name' => 'route-' . $seed . '-' . $i, 'merchant_id' => $self ? $merchant->id : 0,
            'channel_mode' => $self ? 1 : 0, 'plugin_code' => R::PLUGIN, 'api_config_id' => $config->id,
            'pay_type_id' => $typeId, 'status' => 1, 'sort_no' => $i, 'split_rate_bp' => 9500])->fresh();
        $channels[] = $channel;
        if (!$self) PaymentPollGroupChannel::create(['poll_group_id' => $poll->id, 'channel_id' => $channel->id,
            'sort_no' => $i, 'weight' => $i === 0 ? 99 : 1, 'is_default' => $i === 0 ? 1 : 0, 'status' => 1]);
    }
    return ['merchant' => $merchant, 'group' => $group, 'poll' => $poll, 'type_id' => $typeId, 'channels' => $channels];
}

function routingInput(array $f, string $no = '', int $amount = 10000): array
{
    return ['merchant_id' => (int) $f['merchant']->id, 'pay_type_id' => (int) $f['type_id'], 'pay_amount' => $amount,
        'merchant_order_no' => $no ?: 'RT-' . bin2hex(random_bytes(8)), 'subject' => '路由集成测试', 'body' => '',
        'notify_url' => '', 'return_url' => '', 'client_ip' => '127.0.0.1', 'device' => 'pc', 'ext_json' => []];
}

function routingSnapshot(array $f, int $amount = 10000, bool $preview = true): array
{
    return Container::get(PaymentRouteService::class)->resolveByMerchantGroup((int) $f['group']->id, (int) $f['type_id'], $amount,
        ['merchant_id' => (int) $f['merchant']->id, '_preview' => $preview]);
}

/** A deliberately stale, otherwise real route exercises the post-preview race without test hooks in production. */
class FrozenReceiptRoute extends PaymentRouteService
{
    public function __construct(private array $snapshot) {}
    public function resolveByMerchantGroup(int $merchantGroupId, int $payTypeId, int $payAmount, array $context = []): array { return $this->snapshot; }
}

function routingAttempts(array $overrides = []): PayOrderAttemptService
{
    $class = new ReflectionClass(PayOrderAttemptService::class);
    $args = [];
    foreach ($class->getConstructor()->getParameters() as $parameter) {
        $type = $parameter->getType()->getName();
        $args[] = $overrides[$type] ?? Container::get($type);
    }
    return $class->newInstanceArgs($args);
}

function routingOccupy(PaymentChannel $channel, int $amount = 10000, bool $prepare = true): PayOrder
{
    $no = 'OCC-' . bin2hex(random_bytes(8));
    $order = PayOrder::create(['pay_no' => $no, 'biz_no' => 'B-' . $no, 'channel_request_no' => $no,
        'channel_id' => $channel->id, 'plugin_code' => R::PLUGIN, 'pay_type_id' => $channel->pay_type_id,
        'pay_amount' => $amount, 'status' => 1, 'request_at' => R::date(time() - 60), 'expire_at' => R::date(time() + 300)]);
    if ($prepare) Container::get(PaymentPluginManager::class)->createByChannel($channel)->pay(['pay_no' => $no, 'amount' => $amount, 'pay_type_code' => 'alipay']);
    return $order->fresh();
}

function routingRejects(callable $call, string $class, string $label): Throwable
{
    $error = null;
    try { $call(); } catch (Throwable $e) { $error = $e; }
    check($error instanceof $class, $label . ($error ? ' [' . $error::class . ': ' . $error->getMessage() . ']' : ' [no rejection]'));
    return $error;
}

function routingCounts(): array
{
    return array_map(fn ($table) => Db::table($table)->count(), ['ma_biz_order', 'ma_pay_order', 'ma_merchant_account_ledger', 'ma_merchant_fund_freeze']);
}
